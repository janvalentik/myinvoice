<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Concurrency / lifecycle logika import jobů — chrání oprava patové situace,
 * kdy mrtvý worker (nespuštěný / spadlý) nechá job navždy queued/running a blokuje
 * nový start i graceful cancel:
 *
 *   - reapStale(): stale queued/running (zmrzlý updated_at) → failed; čerstvé ponechá
 *   - requestCancel(): queued / stale running → cancelled OKAMŽITĚ; čerstvé running
 *     → jen graceful flag (worker dočte)
 *   - delete(): tvrdé odstranění (escape hatch)
 *
 * Soft-skip bez cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class ImportJobLifecycleTest extends TestCase
{
    private Connection $db;
    private ImportJobRepository $repo;
    private int $supplierId = 0;
    private int $userId = 0;
    /** @var int[] */
    private array $created = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->repo = $c->get(ImportJobRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        foreach ($this->created as $id) {
            $this->db->pdo()->prepare('DELETE FROM import_jobs WHERE id = ?')->execute([$id]);
        }
        $this->db->close(); // uvolni MySQL connection (jinak kumulace přes běh → max_connections)
    }

    /** Vytvoří job a vynutí mu status + updated_at (explicitní hodnota přebije ON UPDATE). */
    private function makeJob(string $status, string $updatedAt): int
    {
        $id = $this->repo->create($this->supplierId, 'fakturoid', ['dry_run' => true], $this->userId);
        $this->created[] = $id;
        $this->db->pdo()->prepare(
            'UPDATE import_jobs SET status = ?, updated_at = ? WHERE id = ?'
        )->execute([$status, $updatedAt, $id]);
        return $id;
    }

    private function statusOf(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM import_jobs WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    private function ago(int $minutes): string
    {
        return (new \DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s');
    }

    public function testReapStaleFailsDeadJobsButKeepsFresh(): void
    {
        $staleQueued  = $this->makeJob('queued',  $this->ago(30));
        $staleRunning = $this->makeJob('running', $this->ago(30));
        $freshQueued  = $this->makeJob('queued',  $this->ago(1));
        $freshRunning = $this->makeJob('running', $this->ago(1));
        $done         = $this->makeJob('completed', $this->ago(30));

        $reaped = $this->repo->reapStale($this->supplierId, 'fakturoid');

        $this->assertGreaterThanOrEqual(2, $reaped, 'Aspoň 2 stale joby uklizeny');
        $this->assertSame('failed', $this->statusOf($staleQueued), 'Stale queued → failed');
        $this->assertSame('failed', $this->statusOf($staleRunning), 'Stale running → failed');
        $this->assertSame('queued', $this->statusOf($freshQueued), 'Čerstvý queued ponechán');
        $this->assertSame('running', $this->statusOf($freshRunning), 'Čerstvý running ponechán');
        $this->assertSame('completed', $this->statusOf($done), 'Dokončený nedotčen');
    }

    public function testRequestCancelImmediatelyCancelsQueued(): void
    {
        $id = $this->makeJob('queued', $this->ago(0));
        $ok = $this->repo->requestCancel($id, $this->supplierId);
        $this->assertTrue($ok);
        $this->assertSame('cancelled', $this->statusOf($id), 'Queued se ruší okamžitě (žádný živý worker)');
    }

    public function testRequestCancelImmediatelyCancelsStaleRunning(): void
    {
        $id = $this->makeJob('running', $this->ago(30));
        $ok = $this->repo->requestCancel($id, $this->supplierId);
        $this->assertTrue($ok);
        $this->assertSame('cancelled', $this->statusOf($id), 'Stale running (mrtvý worker) se ruší okamžitě');
    }

    public function testRequestCancelGracefulOnFreshRunning(): void
    {
        $id = $this->makeJob('running', $this->ago(1));
        $ok = $this->repo->requestCancel($id, $this->supplierId);
        $this->assertTrue($ok);
        // Aktivní worker — jen flag, status zůstává running (worker dočte a sám ukončí).
        $this->assertSame('running', $this->statusOf($id), 'Čerstvý running zůstává running (graceful)');
        $stmt = $this->db->pdo()->prepare('SELECT cancel_requested FROM import_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'cancel_requested flag nastaven');
    }

    public function testRequestCancelRejectsForeignTenant(): void
    {
        $id = $this->makeJob('queued', $this->ago(0));
        $ok = $this->repo->requestCancel($id, $this->supplierId + 999);
        $this->assertFalse($ok, 'Cizí tenant nesmí zrušit');
        $this->assertSame('queued', $this->statusOf($id));
    }

    public function testDeleteRemovesJob(): void
    {
        $id = $this->makeJob('completed', $this->ago(5));
        $this->assertTrue($this->repo->delete($id, $this->supplierId));
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM import_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Job smazán');
    }

    public function testDeleteRejectsForeignTenant(): void
    {
        $id = $this->makeJob('completed', $this->ago(5));
        $this->assertFalse($this->repo->delete($id, $this->supplierId + 999), 'Cizí tenant nesmí smazat');
        $this->assertSame('completed', $this->statusOf($id), 'Job zůstal');
    }
}
