<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Search;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rychlé hledání pro globální search box (searchQuick) — matching + tenant scope.
 *   - klient: název i e-mail, scoped na supplier
 *   - vydaná faktura: číslo dokladu (varsymbol)
 *   - přijatá faktura: varsymbol i číslo dodavatele
 *
 * Izolováno (unikátní řetězce), uklizeno v tearDown. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class GlobalSearchTest extends TestCase
{
    private Connection $db;
    private ClientRepository $clients;
    private InvoiceRepository $invoices;
    private PurchaseInvoiceRepository $purchases;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $clientIds = [];
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $purchaseIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db        = $c->get(Connection::class);
            $this->clients   = $c->get(ClientRepository::class);
            $this->invoices  = $c->get(InvoiceRepository::class);
            $this->purchases = $c->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->purchaseIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testClientSearchByNameAndEmailScopedToTenant(): void
    {
        $this->client('Zxqwy Hledací s.r.o.', 'najdi@zxqwy-test.cz');

        $byName = $this->clients->searchQuick('Zxqwy Hledací', $this->supplierId);
        self::assertNotEmpty($byName);
        self::assertSame('Zxqwy Hledací s.r.o.', $byName[0]['company_name']);

        $byEmail = $this->clients->searchQuick('najdi@zxqwy-test', $this->supplierId);
        self::assertNotEmpty($byEmail, 'hledání i podle e-mailu');
        self::assertSame('Zxqwy Hledací s.r.o.', $byEmail[0]['company_name']);

        // Tenant scope: jiný (neexistující) supplier nevrátí nic
        self::assertEmpty($this->clients->searchQuick('Zxqwy Hledací', 999999));
    }

    public function testInvoiceSearchByDocumentNumber(): void
    {
        $client = $this->client('Odběratel SRCH', null);
        $this->sale('2099ZXSRCH1', $client);

        $hits = $this->invoices->searchQuick('ZXSRCH1', $this->supplierId);
        self::assertNotEmpty($hits);
        self::assertSame('2099ZXSRCH1', $hits[0]['varsymbol']);
        self::assertEmpty($this->invoices->searchQuick('ZXSRCH1', 999999), 'tenant scope');
    }

    public function testPurchaseSearchByVarsymbolAndVendorNumber(): void
    {
        $vendor = $this->client('Dodavatel SRCH', null, vendor: true);
        $this->purchase('PF2099ZXSRCH', 'VEND-ZXSRCH-9', $vendor);

        $byVs = $this->purchases->searchQuick('PF2099ZXSRCH', $this->supplierId);
        self::assertNotEmpty($byVs, 'hledání podle našeho varsymbolu');

        $byVendorNo = $this->purchases->searchQuick('VEND-ZXSRCH', $this->supplierId);
        self::assertNotEmpty($byVendorNo, 'hledání podle čísla dodavatele');
        self::assertEmpty($this->purchases->searchQuick('VEND-ZXSRCH', 999999), 'tenant scope');
    }

    // ── helpers ──

    private function client(string $name, ?string $email, bool $vendor = false): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, NULL, ?, "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $email ?? '', $this->currencyId, $vendor ? 0 : 1, $vendor ? 1 : 0]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    private function sale(string $varsymbol, int $clientId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, "invoice", ?, "2099-06-10", "2099-06-10", "2099-06-24", ?, 0, 1000, 0, 1000, "issued", ?)'
        );
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $this->currencyId, $this->userId]);
        $this->invoiceIds[] = (int) $this->db->pdo()->lastInsertId();
    }

    private function purchase(string $varsymbol, string $vendorNumber, int $vendorId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, varsymbol, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, "invoice", "2099-06-10", "2099-06-10", "2099-06-24", "2099-06-10", ?, 0, "{}",
                     1000, 0, 1000, "received", ?)'
        );
        $stmt->execute([$this->supplierId, $vendorId, $vendorNumber, $varsymbol, $this->currencyId, $this->userId]);
        $this->purchaseIds[] = (int) $this->db->pdo()->lastInsertId();
    }
}
