<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Samoopravné číslování přijatých faktur (issue #103, paralela k #85 u vydaných):
 * nextVarsymbol() nesmí vrátit interní číslo, které už existuje (ruční číslo
 * „dopředu", import, ruční úprava DB) — buď přeskočí na volné, nebo skočí za
 * nejvyšší použité číslo dané řady (období). Unique index uq_pi_supplier_varsymbol
 * je definitivní pojistka.
 *
 * Izolace: období 2099-06 (period "209906" → varsymbol PFxxYYMM = PF9906…), aby
 * se nekřížilo s reálnými daty. Soft-skip bez cfg.php / DB.
 */
#[Group('integration')]
final class PurchaseVarsymbolCounterSyncTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $period = '209906';
    /** @var int[] */
    private array $created = [];
    private int $seq = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->repo = $c->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $this->vendorId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vendorId === 0 || $this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí vendor/CZK currency/user.');
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        $pdo->prepare("DELETE FROM purchase_invoice_counters WHERE supplier_id = ? AND period LIKE '2099%'")
            ->execute([$this->supplierId]);
    }

    /** Vloží přijatou fakturu s daným interním číslem (varsymbol). */
    private function insertPurchase(string $varsymbol): void
    {
        $pdo = $this->db->pdo();
        // vendor_invoice_number musí být unikátní per (supplier, vendor, issue_date) → varíruj.
        $vin = 'TEST-' . $this->period . '-' . (++$this->seq);
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, due_date, received_at, currency_id, vendor_snapshot, status, created_by)
             VALUES (?, ?, ?, ?, 'invoice', '2099-06-15', '2099-06-30', '2099-06-15', ?, '{}', 'received', ?)"
        )->execute([
            $this->supplierId, $this->vendorId, $varsymbol, $vin, $this->currencyId, $this->userId,
        ]);
        $this->created[] = (int) $pdo->lastInsertId();
    }

    public function testHappyPathReturnsFirstNumberWhenClean(): void
    {
        $vs = $this->repo->nextVarsymbol($this->supplierId, $this->period, 'PF');
        self::assertSame('PF9906001', $vs);
    }

    public function testSkipsAlreadyUsedNumber(): void
    {
        // Counter scope je čistý (0). Obsadíme PF9906001 napřímo (jako po importu / ručně).
        $this->insertPurchase('PF9906001');

        // nextVarsymbol by naivně vrátil 001 → musí přeskočit na první volné (002).
        $vs = $this->repo->nextVarsymbol($this->supplierId, $this->period, 'PF');
        self::assertSame('PF9906002', $vs, 'Generátor nesmí vrátit již použité číslo.');
    }

    public function testJumpsPastHighestUsedNumber(): void
    {
        // Obsazené 001 (vynutí heal větev) + vysoká čísla 040, 042 v témže období.
        $this->insertPurchase('PF9906001');
        $this->insertPurchase('PF9906040');
        $this->insertPurchase('PF9906042');

        // Musí skočit za nejvyšší použité (42) → 043, ne jen na 002.
        $vs = $this->repo->nextVarsymbol($this->supplierId, $this->period, 'PF');
        self::assertSame('PF9906043', $vs);
    }

    public function testHighestUsedCountsAcrossPrefixes(): void
    {
        // Čítač je sdílený napříč prefixy — nejvyšší použité číslo se počítá i z jiného
        // prefixu (NN). Obsadíme PF9906001 (heal trigger) + NN9906050.
        $this->insertPurchase('PF9906001');
        $this->insertPurchase('NN9906050');

        $vs = $this->repo->nextVarsymbol($this->supplierId, $this->period, 'PF');
        self::assertSame('PF9906051', $vs, 'Skok musí zohlednit i čísla s jiným prefixem.');
    }
}
