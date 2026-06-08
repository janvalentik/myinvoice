<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/**
 * End-to-end test PDF přílohy k bankovnímu výpisu (uploadPdf/downloadPdf/deletePdf).
 *
 * Vytvoří dočasný bank_statements řádek s account_number existující měny dev DB
 * (aby prošel supplier scope), volá akce přímo s mock requestem a ověří:
 *   - upload validního PDF → uloží se, has_pdf=true, bajty sedí
 *   - download → application/pdf + obsah
 *   - upload ne-PDF → 400
 *   - cizí supplier → 404
 *   - role bez práv → 403
 *   - delete → vyčistí, download pak 404
 */
#[Group('integration')]
final class BankStatementPdfTest extends TestCase
{
    private Connection $db;
    private BankStatementAction $action;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $statementId = 0;
    /** @var string[] temp soubory k úklidu */
    private array $tmpFiles = [];

    private const PDF_BYTES = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $app = Bootstrap::buildApp();
            $container = $app->getContainer();
            if ($container === null) $this->markTestSkipped('Container not available');
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(BankStatementAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        // Měna s vyplněným account_number → z ní odvodíme supplier scope + vytvoříme výpis.
        $row = $this->db->pdo()->query(
            "SELECT supplier_id, account_number, bank_code FROM currencies
              WHERE account_number IS NOT NULL AND account_number <> '' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('Žádná měna s account_number — nelze otestovat scope.');
        }
        $this->supplierId = (int) $row['supplier_id'];
        $this->userId = (int) ($this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

        $this->db->pdo()->prepare(
            'INSERT INTO bank_statements (file_name, file_hash, account_number, bank_code, statement_date, transaction_count)
             VALUES (?, ?, ?, ?, CURDATE(), 0)'
        )->execute([
            'TEST-pdf-attach.gpc',
            hash('sha256', 'test-' . uniqid('', true)),
            (string) $row['account_number'],
            $row['bank_code'] !== null ? (string) $row['bank_code'] : null,
        ]);
        $this->statementId = (int) $this->db->pdo()->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->statementId > 0 && isset($this->db)) {
            $this->db->pdo()->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$this->statementId]);
        }
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) @unlink($f);
        }
        if (isset($this->db)) $this->db->close();
    }

    private function mockRequest(int $sid, string $role, array $files = []): ServerRequestInterface
    {
        $req = $this->createStub(ServerRequestInterface::class);
        $req->method('getAttribute')->willReturnCallback(function (string $name, $default = null) use ($sid, $role) {
            if ($name === SupplierScopeMiddleware::ATTR_CURRENT_ID) return $sid;
            if ($name === AuthMiddleware::ATTR_USER) return ['id' => $this->userId, 'role' => $role];
            return $default;
        });
        $req->method('getUploadedFiles')->willReturn($files);
        $req->method('getServerParams')->willReturn([]);
        $req->method('getHeaderLine')->willReturn('');
        return $req;
    }

    private function uploadedFile(string $content, string $name = 'vypis.pdf'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdftest');
        file_put_contents($tmp, $content);
        $this->tmpFiles[] = $tmp;
        return new UploadedFile($tmp, $name, 'application/pdf', strlen($content), UPLOAD_ERR_OK);
    }

    private function hasPdf(): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT pdf_content IS NOT NULL FROM bank_statements WHERE id = ?');
        $stmt->execute([$this->statementId]);
        return (bool) $stmt->fetchColumn();
    }

    public function testUploadValidPdfThenDownloadThenDelete(): void
    {
        // 1) Upload
        $req = $this->mockRequest($this->supplierId, 'admin', ['file' => $this->uploadedFile(self::PDF_BYTES)]);
        $resp = $this->action->uploadPdf($req, new Response(), ['id' => $this->statementId]);
        $this->assertSame(200, $resp->getStatusCode(), 'upload validního PDF musí projít');
        $this->assertTrue($this->hasPdf(), 'has_pdf v DB musí být true');

        // bajty v DB sedí
        $stmt = $this->db->pdo()->prepare('SELECT pdf_content, pdf_name, pdf_size_bytes FROM bank_statements WHERE id = ?');
        $stmt->execute([$this->statementId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(self::PDF_BYTES, (string) $r['pdf_content']);
        $this->assertSame('vypis.pdf', $r['pdf_name']);
        $this->assertSame(strlen(self::PDF_BYTES), (int) $r['pdf_size_bytes']);

        // 2) Download
        $dl = $this->action->downloadPdf($this->mockRequest($this->supplierId, 'admin'), new Response(), ['id' => $this->statementId]);
        $this->assertSame(200, $dl->getStatusCode());
        $this->assertSame('application/pdf', $dl->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment', $dl->getHeaderLine('Content-Disposition'));
        $this->assertSame(self::PDF_BYTES, (string) $dl->getBody());

        // 3) Delete
        $del = $this->action->deletePdf($this->mockRequest($this->supplierId, 'admin'), new Response(), ['id' => $this->statementId]);
        $this->assertSame(200, $del->getStatusCode());
        $this->assertFalse($this->hasPdf(), 'po smazání musí být has_pdf false');

        // 4) Download po smazání → 404
        $dl2 = $this->action->downloadPdf($this->mockRequest($this->supplierId, 'admin'), new Response(), ['id' => $this->statementId]);
        $this->assertSame(404, $dl2->getStatusCode());
    }

    public function testUploadRejectsNonPdf(): void
    {
        $req = $this->mockRequest($this->supplierId, 'admin', ['file' => $this->uploadedFile('this is not a pdf', 'fake.pdf')]);
        $resp = $this->action->uploadPdf($req, new Response(), ['id' => $this->statementId]);
        $this->assertSame(400, $resp->getStatusCode(), 'ne-PDF obsah musí být odmítnut');
        $this->assertFalse($this->hasPdf());
    }

    public function testUploadRejectsNonPdfExtension(): void
    {
        $req = $this->mockRequest($this->supplierId, 'admin', ['file' => $this->uploadedFile(self::PDF_BYTES, 'vypis.txt')]);
        $resp = $this->action->uploadPdf($req, new Response(), ['id' => $this->statementId]);
        $this->assertSame(400, $resp->getStatusCode(), 'jiná přípona než .pdf musí být odmítnuta');
    }

    public function testForeignSupplierGets404(): void
    {
        $otherSid = $this->supplierId + 99999; // neexistující / cizí supplier
        $req = $this->mockRequest($otherSid, 'admin', ['file' => $this->uploadedFile(self::PDF_BYTES)]);
        $resp = $this->action->uploadPdf($req, new Response(), ['id' => $this->statementId]);
        $this->assertSame(404, $resp->getStatusCode(), 'cizí supplier nesmí nahrát PDF k cizímu výpisu');
        $this->assertFalse($this->hasPdf());
    }

    public function testReadonlyRoleForbidden(): void
    {
        $req = $this->mockRequest($this->supplierId, 'readonly', ['file' => $this->uploadedFile(self::PDF_BYTES)]);
        $resp = $this->action->uploadPdf($req, new Response(), ['id' => $this->statementId]);
        $this->assertSame(403, $resp->getStatusCode(), 'role bez zápisu nesmí nahrávat');
    }
}
