<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Sample;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Sample\SampleDataGenerator;
use MyInvoice\Service\Sample\SampleDataService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Evidence + přesné odebrání ukázkových dat (issue #162) end-to-end:
 *
 *   1. generate() vytvoří sample a každou kořenovou entitu zaeviduje (sample_data_entries).
 *   2. guard: druhý generate nad neprázdnou DB selže (žádné duplicity / pád na UNIQUE).
 *   3. purge() smaže celou sample dávku FK-bezpečně (děti kaskádou, projects bez supplier_id).
 *
 * Izolace: throwaway dodavatel s vlastními měnami, úklid v tearDown. Soft-skip bez cfg.php/DB.
 */
#[Group('integration')]
final class SampleDataPurgeTest extends TestCase
{
    private Connection $db;
    private SampleDataGenerator $generator;
    private SampleDataService $service;

    private int $supplierId = 0;
    private int $adminId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db        = $c->get(Connection::class);
            $this->generator = $c->get(SampleDataGenerator::class);
            $this->service   = $c->get(SampleDataService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->adminId = (int) $pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $vatId = (int) $pdo->query("SELECT id FROM vat_rates WHERE code='CZ-21' LIMIT 1")->fetchColumn();
        $anyCur = (int) $pdo->query("SELECT id FROM currencies LIMIT 1")->fetchColumn();
        if (!$this->adminId || !$countryId || !$vatId || !$anyCur) {
            $this->markTestSkipped('Chybí předpoklady (admin/country/vat/currency).');
        }

        // Throwaway dodavatel + CZK/EUR měny (generátor je řeší per supplier_id+code).
        $pdo->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES ('__SAMPLE_PURGE_TEST__','Test 1','Praha','11000', ?, 'samplepurge@example.invalid', ?, ?)"
        )->execute([$countryId, $anyCur, $vatId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $insCur = $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?,?,?,?,?,?,2,1,?)"
        );
        $insCur->execute([$this->supplierId, 'CZK', 'Kč', 'Kč', 'koruna', 'crown', 1]);
        $czk = (int) $pdo->lastInsertId();
        $insCur->execute([$this->supplierId, 'EUR', 'EUR', '€', 'euro', 'euro', 0]);
        $pdo->prepare("UPDATE supplier SET default_currency_id=? WHERE id=?")->execute([$czk, $this->supplierId]);
    }

    protected function tearDown(): void
    {
        if ($this->supplierId <= 0 || !isset($this->db)) return;
        $pdo = $this->db->pdo();
        try { $this->service->purge($this->supplierId); } catch (\Throwable) {}
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->prepare('DELETE FROM sample_data_entries WHERE supplier_id=?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id=?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplierId]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testGenerateTracksAndPurgeRemovesEverything(): void
    {
        $pdo = $this->db->pdo();

        $r = $this->generator->generate($this->supplierId, $this->adminId);
        self::assertSame(5, $r['clients']);
        self::assertSame(20, $r['invoices']);

        // Evidence: 5 client + 8 project + 20 invoice + 4 credit_note + 4 vendor + 12 PI + 2 recurring + 1 car = 56
        $tracked = (int) $pdo->query("SELECT COUNT(*) FROM sample_data_entries WHERE supplier_id={$this->supplierId}")->fetchColumn();
        self::assertSame(56, $tracked);

        $sum = $this->service->summary($this->supplierId);
        self::assertTrue($sum['has']);
        self::assertSame(56, $sum['total']);
        self::assertSame(20, $sum['counts']['invoice']);

        // Před purge data existují (clients = 5 zákazníků + 4 dodavatelé).
        self::assertSame(9, (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE supplier_id={$this->supplierId}")->fetchColumn());

        $del = $this->service->purge($this->supplierId);
        self::assertSame(9, $del['clients']);
        self::assertSame(12, $del['purchase_invoices']);

        // Po purge je vše pryč.
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM cars WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertFalse($this->service->hasSampleData($this->supplierId));
    }

    public function testSecondGenerateIsBlockedByGuard(): void
    {
        $this->generator->generate($this->supplierId, $this->adminId);

        $this->expectException(\RuntimeException::class);
        $this->generator->generate($this->supplierId, $this->adminId);
    }
}
