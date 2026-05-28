<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\DocumentRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační test DB vrstvy sekce Dokumenty: insert, fulltext, soft-delete (koš),
 * restore, dedup (countBySha), strom složek. Volá repozitáře přímo (bez HTTP),
 * potřebuje jen DB. Vše per-supplier, vlastní data se po sobě uklízí.
 */
#[Group('integration')]
final class DocumentRepositoryTest extends TestCase
{
    private PDO $pdo;
    private DocumentRepository $docs;
    private DocumentFolderRepository $folders;
    private int $supplierId;
    /** @var list<int> */
    private array $createdDocs = [];
    /** @var list<int> */
    private array $createdFolders = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $app = Bootstrap::buildApp();
            $c = $app->getContainer();
            if ($c === null) $this->markTestSkipped('Container not available');
            $this->pdo = $c->get(Connection::class)->pdo();
            $this->docs = $c->get(DocumentRepository::class);
            $this->folders = $c->get(DocumentFolderRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
        $this->supplierId = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierId <= 0) $this->markTestSkipped('No supplier');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDocs as $id) {
            $this->pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
        }
        foreach ($this->createdFolders as $id) {
            $this->pdo->prepare('DELETE FROM document_folders WHERE id = ?')->execute([$id]);
        }
    }

    private function insertDoc(string $title, string $sha, ?int $folderId = null): int
    {
        $id = $this->docs->insert([
            'supplier_id'   => $this->supplierId,
            'folder_id'     => $folderId,
            'title'         => $title,
            'description'   => null,
            'original_name' => $title . '.pdf',
            'filename'      => substr($sha, 0, 8) . '-' . $title . '.pdf',
            'sha256'        => $sha,
            'mime_type'     => 'application/pdf',
            'size_bytes'    => 1000,
            'doc_type'      => 'pdf',
            'uploaded_by'   => null,
        ]);
        $this->createdDocs[] = $id;
        return $id;
    }

    public function testInsertFindAndSupplierScope(): void
    {
        $id = $this->insertDoc('UNIQTESTDOC alpha', str_repeat('1', 64));
        $found = $this->docs->find($id, $this->supplierId);
        self::assertNotNull($found);
        self::assertSame('UNIQTESTDOC alpha', $found['title']);
        // Jiný supplier nevidí
        self::assertNull($this->docs->find($id, $this->supplierId + 99999));
    }

    public function testFulltextSearchFindsByTitleAndContent(): void
    {
        $id = $this->insertDoc('ZZQXMARKER smlouva', str_repeat('2', 64));
        $this->docs->setText($id, 'Obsah dokumentu se slovem KONTROLNIMARKER uvnitř.', 'extracted');

        $byTitle = $this->docs->search($this->supplierId, 'ZZQXMARKER');
        self::assertContains($id, array_map(static fn($r) => $r['id'], $byTitle));

        $byContent = $this->docs->search($this->supplierId, 'KONTROLNIMARKER');
        self::assertContains($id, array_map(static fn($r) => $r['id'], $byContent));
    }

    public function testSoftDeleteRestoreLifecycle(): void
    {
        $id = $this->insertDoc('TRASHTESTDOC', str_repeat('3', 64));
        self::assertTrue($this->docs->softDelete($id, $this->supplierId, null));

        // Po smazání není v aktivním listu, ale je v koši.
        self::assertNull($this->docs->find($id, $this->supplierId));
        self::assertNotNull($this->docs->find($id, $this->supplierId, true));
        $trashIds = array_map(static fn($r) => $r['id'], $this->docs->listTrash($this->supplierId));
        self::assertContains($id, $trashIds);

        self::assertTrue($this->docs->restore($id, $this->supplierId));
        self::assertNotNull($this->docs->find($id, $this->supplierId));
    }

    public function testDedupCountBySha(): void
    {
        $sha = str_repeat('4', 64);
        $a = $this->insertDoc('DEDUP A', $sha);
        $b = $this->insertDoc('DEDUP B', $sha);

        // Při mazání obou: kromě [a,b] nezbývá žádný odkaz → orphan.
        self::assertSame(0, $this->docs->countBySha($this->supplierId, $sha, [$a, $b]));
        // Při mazání jen a: b stále drží soubor → není orphan.
        self::assertGreaterThanOrEqual(1, $this->docs->countBySha($this->supplierId, $sha, [$a]));
    }

    public function testListByEntityReturnsLinkedDocuments(): void
    {
        // Regrese: SELECT v listByEntity musí kvalifikovat sloupce — join s
        // document_links zavádí druhý created_at (jinak „ambiguous column" → 500).
        $id = $this->insertDoc('LINKEDDOC', str_repeat('6', 64));
        $this->pdo->prepare(
            'INSERT INTO document_links (document_id, entity_type, entity_id) VALUES (?, "invoice", ?)'
        )->execute([$id, 987654]);

        $res = $this->docs->listByEntity($this->supplierId, 'invoice', 987654);
        self::assertContains($id, array_map(static fn($r) => $r['id'], $res));

        $this->pdo->prepare('DELETE FROM document_links WHERE document_id = ?')->execute([$id]);
    }

    public function testFolderTreeAndCascadeSoftDelete(): void
    {
        $parent = $this->folders->create($this->supplierId, null, 'ITESTPARENT', null);
        $this->createdFolders[] = $parent;
        $child = $this->folders->create($this->supplierId, $parent, 'ITESTCHILD', null);
        $this->createdFolders[] = $child;

        $docId = $this->insertDoc('INFOLDER', str_repeat('5', 64), $child);

        $descendants = $this->folders->descendantIds($parent, $this->supplierId);
        self::assertContains($child, $descendants);

        // Soft-delete podstromu → dokument uvnitř spadne do koše.
        $this->folders->softDeleteSubtree($parent, $this->supplierId, null);
        self::assertNull($this->docs->find($docId, $this->supplierId));
        self::assertNotNull($this->docs->find($docId, $this->supplierId, true));
    }
}
