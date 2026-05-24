<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test pravidelné fakturace.
 *
 * Vytvoří dočasnou šablonu (s existujícím supplier/client/currency/vat_rate
 * z dev DB), zavolá RecurringInvoiceGenerator přímo (stejně jako cron), ověří:
 *   - faktura vznikla s vazbou recurring_template_id
 *   - položky z šablony se zkopírovaly
 *   - cron-flag auto_issue=true → faktura má varsymbol + status='issued'
 *   - šablona má posunutý next_run_date
 *   - posun popisu měsíce funguje (M/YYYY → +1 měsíc u monthly)
 *
 * Po sobě uklízí všechno (šablonu + vygenerovanou fakturu + items).
 */
#[Group('integration')]
final class RecurringGeneratorTest extends TestCase
{
    private Connection $db;
    private RecurringInvoiceGenerator $generator;
    private RecurringTemplateRepository $repo;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;

    /** @var int[] šablony k vyčištění */
    private array $createdTemplateIds = [];
    /** @var int[] faktury k vyčištění */
    private array $createdInvoiceIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }

        try {
            $app = Bootstrap::buildApp();
            $container = $app->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->db = $container->get(Connection::class);
            $this->generator = $container->get(RecurringInvoiceGenerator::class);
            $this->repo = $container->get(RecurringTemplateRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        // Vezmi první existující supplier (aby kill-switch byl 1)
        $row = $this->db->pdo()->query(
            "SELECT id FROM supplier WHERE auto_generate_recurring = 1 LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('Žádný supplier s auto_generate_recurring=1');
        }
        $this->supplierId = (int) $row['id'];

        $row = $this->db->pdo()->prepare(
            "SELECT id FROM clients WHERE supplier_id = ? AND archived_at IS NULL LIMIT 1"
        );
        $row->execute([$this->supplierId]);
        $clientId = (int) $row->fetchColumn();
        if ($clientId <= 0) {
            $this->markTestSkipped("Supplier #{$this->supplierId} nemá žádné klienty");
        }
        $this->clientId = $clientId;

        $row = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 LIMIT 1"
        );
        $row->execute([$this->supplierId]);
        $this->currencyId = (int) $row->fetchColumn();
        if ($this->currencyId <= 0) {
            $this->markTestSkipped('Supplier nemá žádnou aktivní měnu');
        }

        $this->vatRateId = (int) $this->db->pdo()
            ->query("SELECT id FROM vat_rates WHERE is_reverse_charge = 0 ORDER BY is_default DESC, rate_percent DESC LIMIT 1")
            ->fetchColumn();
        if ($this->vatRateId <= 0) {
            $this->markTestSkipped('Žádná použitelná VAT sazba');
        }

        $this->userId = (int) $this->db->pdo()
            ->query("SELECT id FROM users ORDER BY id LIMIT 1")
            ->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (empty($this->createdInvoiceIds) && empty($this->createdTemplateIds)) {
            if (isset($this->db)) $this->db->close();
            return;
        }
        $pdo = $this->db->pdo();
        // Faktury smazat dřív než šablonu (kvůli FK fk_inv_recurring SET NULL by to
        // teoreticky zvládlo, ale chceme řízený cleanup).
        foreach ($this->createdInvoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->createdTemplateIds as $id) {
            $pdo->prepare('DELETE FROM recurring_invoice_template_items WHERE template_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM recurring_invoice_templates WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testGeneratorCreatesIssuedInvoiceWithLinkBack(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $tplId = $this->repo->create([
            'supplier_id'    => $this->supplierId,
            'client_id'      => $this->clientId,
            'project_id'     => null,
            'name'           => 'TEST recurring (PHPUnit)',
            'frequency'      => 'monthly',
            'day_of_month'   => null,
            'end_of_month'   => false,
            'anchor_date'    => $today,
            'next_run_date'  => $today,
            'end_date'       => null,
            'invoice_type'   => 'invoice',
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'reverse_charge' => false,
            'payment_due_days' => 14,
            'note_above_items' => null,
            'note_below_items' => null,
            'increment_month_in_descriptions' => true,
            'auto_issue'     => true,
            'auto_send_email'=> false,  // ne odesílat, ať se test nesnaží SMTP
            'status'         => 'active',
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        $this->repo->replaceItems($tplId, [
            [
                'description' => 'Hosting ' . (new \DateTimeImmutable($today))->format('n/Y'),
                'quantity' => 1.0,
                'unit' => 'měs',
                'unit_price_without_vat' => 500.00,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 0,
            ],
            [
                'description' => 'Support paušál',
                'quantity' => 2.0,
                'unit' => 'h',
                'unit_price_without_vat' => 1500.00,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 1,
            ],
        ]);

        $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $result['invoice_id'];

        // Vygenerovaná faktura — basic asserts
        $this->assertGreaterThan(0, $result['invoice_id']);
        $this->assertTrue($result['issued'], 'auto_issue=true musí vystavit fakturu');
        $this->assertNotNull($result['varsymbol'], 'Vystavená faktura musí mít varsymbol');
        $this->assertEmpty($result['sent_to'], 'auto_send_email=false → žádné e-maily');

        // Faktura v DB
        $inv = $this->db->pdo()->prepare(
            "SELECT id, status, varsymbol, recurring_template_id, total_with_vat, supplier_id, client_id, currency_id, payment_method
               FROM invoices WHERE id = ?"
        );
        $inv->execute([$result['invoice_id']]);
        $row = $inv->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'Faktura existuje');
        $this->assertSame('issued', $row['status']);
        $this->assertNotEmpty($row['varsymbol']);
        $this->assertSame($tplId, (int) $row['recurring_template_id']);
        $this->assertSame($this->supplierId, (int) $row['supplier_id']);
        $this->assertSame($this->clientId, (int) $row['client_id']);
        $this->assertSame($this->currencyId, (int) $row['currency_id']);
        $this->assertSame('bank_transfer', $row['payment_method']);
        // 1×500 + 2×1500 = 3500 base + DPH (default rate je >0)
        $this->assertGreaterThanOrEqual(3500.00, (float) $row['total_with_vat']);

        // Položky se zkopírovaly
        $items = $this->db->pdo()->prepare(
            "SELECT description, quantity, unit_price_without_vat FROM invoice_items
              WHERE invoice_id = ? ORDER BY order_index"
        );
        $items->execute([$result['invoice_id']]);
        $itemRows = $items->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $itemRows);

        // Description sync — M/YYYY se synchronizuje k tax_date (= issue_date při default
        // tax_date_mode='same_as_issue'). Popis šablony "Hosting 5/2026" generuje
        // "Hosting 5/2026" pokud issue_date spadá do 5/2026, ne "Hosting 6/2026".
        $currentMonth = (int) (new \DateTimeImmutable($today))->format('n');
        $currentYear  = (int) (new \DateTimeImmutable($today))->format('Y');
        $this->assertStringContainsString(
            "Hosting {$currentMonth}/{$currentYear}",
            $itemRows[0]['description'],
            'description sync má synchronizovat M/YYYY na měsíc DUZP/issue_date',
        );

        // Šablona má posunutý next_run_date a last_run_date
        $tplRow = $this->db->pdo()->prepare(
            "SELECT next_run_date, last_run_date, status FROM recurring_invoice_templates WHERE id = ?"
        );
        $tplRow->execute([$tplId]);
        $tplData = $tplRow->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($today, $tplData['last_run_date']);
        $this->assertNotSame($today, $tplData['next_run_date'], 'next_run_date musí být posunut');
        $this->assertGreaterThan($today, $tplData['next_run_date']);
        $this->assertSame('active', $tplData['status']);
    }

    public function testGeneratorDraftOnlyWhenAutoIssueFalse(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $tplId = $this->repo->create([
            'supplier_id'    => $this->supplierId,
            'client_id'      => $this->clientId,
            'name'           => 'TEST recurring draft-only (PHPUnit)',
            'frequency'      => 'monthly',
            'end_of_month'   => false,
            'anchor_date'    => $today,
            'next_run_date'  => $today,
            'invoice_type'   => 'invoice',
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'payment_due_days' => 7,
            'increment_month_in_descriptions' => false,
            'auto_issue'     => false,
            'auto_send_email'=> false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        $this->repo->replaceItems($tplId, [[
            'description' => 'Konzultace',
            'quantity' => 1.0,
            'unit' => 'h',
            'unit_price_without_vat' => 1000.00,
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
        ]]);

        $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $result['invoice_id'];

        $this->assertFalse($result['issued']);
        $this->assertNull($result['varsymbol']);

        $row = $this->db->pdo()->prepare("SELECT status, varsymbol FROM invoices WHERE id = ?");
        $row->execute([$result['invoice_id']]);
        $data = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('draft', $data['status']);
        $this->assertNull($data['varsymbol']);
    }

    public function testGeneratorPreviousMonthLastDayTaxDateMode(): void
    {
        // Šablona s tax_date_mode='previous_month_last_day': fakturuje se 1.6. za 5/2026,
        // DUZP = 31.5.2026, popis položky se synchronizuje na "5/2026" (ne issue date 6/2026).
        $issueDate = '2026-06-01';

        $tplId = $this->repo->create([
            'supplier_id'    => $this->supplierId,
            'client_id'      => $this->clientId,
            'name'           => 'TEST recurring tax_date previous-month (PHPUnit)',
            'frequency'      => 'monthly',
            'end_of_month'   => false,
            'anchor_date'    => $issueDate,
            'next_run_date'  => $issueDate,
            'invoice_type'   => 'invoice',
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'payment_due_days' => 14,
            'tax_date_mode'  => 'previous_month_last_day',
            'increment_month_in_descriptions' => true,
            'auto_issue'     => true,
            'auto_send_email'=> false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        $this->repo->replaceItems($tplId, [[
            'description' => 'Hosting 06/2026',  // šablona může mít libovolný měsíc — sync to přepíše
            'quantity' => 1.0,
            'unit' => 'měs',
            'unit_price_without_vat' => 500.00,
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
        ]]);

        $result = $this->generator->generate($tplId, $issueDate, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $result['invoice_id'];

        $row = $this->db->pdo()->prepare(
            "SELECT i.issue_date, i.tax_date, ii.description
               FROM invoices i
               JOIN invoice_items ii ON ii.invoice_id = i.id
              WHERE i.id = ?"
        );
        $row->execute([$result['invoice_id']]);
        $data = $row->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('2026-06-01', $data['issue_date']);
        $this->assertSame('2026-05-31', $data['tax_date'], 'DUZP musí být poslední den předchozího měsíce');
        $this->assertStringContainsString('05/2026', $data['description'], 'Popis se musí synchronizovat na měsíc DUZP (5/2026), ne issue date (6/2026)');
    }

    public function testGeneratorRejectsTemplateWithNonPositiveAmountToPay(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $tplId = $this->repo->create([
            'supplier_id'    => $this->supplierId,
            'client_id'      => $this->clientId,
            'name'           => 'TEST recurring invalid discount (PHPUnit)',
            'frequency'      => 'monthly',
            'end_of_month'   => false,
            'anchor_date'    => $today,
            'next_run_date'  => $today,
            'invoice_type'   => 'invoice',
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'payment_due_days' => 7,
            'increment_month_in_descriptions' => false,
            'auto_issue'     => false,
            'auto_send_email'=> false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        $this->repo->replaceItems($tplId, [
            [
                'description' => 'Paušál',
                'quantity' => 1.0,
                'unit' => 'h',
                'unit_price_without_vat' => 1000.00,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 0,
            ],
            [
                'description' => 'Sleva 100 %',
                'quantity' => 1.0,
                'unit' => 'h',
                'unit_price_without_vat' => -1000.00,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 1,
            ],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.');

        try {
            $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        } finally {
            $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE recurring_template_id = ?');
            $stmt->execute([$tplId]);
            $this->assertSame(0, (int) $stmt->fetchColumn(), 'Neplatný recurring draft se nesmí uložit.');
        }
    }

    public function testFindDueIncludesActiveAndSkipsPaused(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $activeId = $this->repo->create([
            'supplier_id'  => $this->supplierId,
            'client_id'    => $this->clientId,
            'name'         => 'TEST due active (PHPUnit)',
            'frequency'    => 'monthly',
            'end_of_month' => false,
            'anchor_date'  => $today,
            'next_run_date'=> $today,
            'currency_id'  => $this->currencyId,
            'language'     => 'cs',
            'payment_method' => 'bank_transfer',
            'auto_issue'   => false,
            'auto_send_email' => false,
            'status'       => 'active',
        ], $this->userId);
        $this->createdTemplateIds[] = $activeId;

        $pausedId = $this->repo->create([
            'supplier_id'  => $this->supplierId,
            'client_id'    => $this->clientId,
            'name'         => 'TEST due paused (PHPUnit)',
            'frequency'    => 'monthly',
            'end_of_month' => false,
            'anchor_date'  => $today,
            'next_run_date'=> $today,
            'currency_id'  => $this->currencyId,
            'language'     => 'cs',
            'payment_method' => 'bank_transfer',
            'auto_issue'   => false,
            'auto_send_email' => false,
            'status'       => 'paused',
        ], $this->userId);
        $this->createdTemplateIds[] = $pausedId;

        $futureId = $this->repo->create([
            'supplier_id'  => $this->supplierId,
            'client_id'    => $this->clientId,
            'name'         => 'TEST due future (PHPUnit)',
            'frequency'    => 'monthly',
            'end_of_month' => false,
            'anchor_date'  => (new \DateTimeImmutable($today))->modify('+1 month')->format('Y-m-d'),
            'next_run_date'=> (new \DateTimeImmutable($today))->modify('+1 month')->format('Y-m-d'),
            'currency_id'  => $this->currencyId,
            'language'     => 'cs',
            'payment_method' => 'bank_transfer',
            'auto_issue'   => false,
            'auto_send_email' => false,
            'status'       => 'active',
        ], $this->userId);
        $this->createdTemplateIds[] = $futureId;

        $due = $this->repo->findDue();
        $dueIds = array_map(fn ($t) => (int) $t['id'], $due);

        $this->assertContains($activeId, $dueIds, 'Aktivní šablona s dnešním next_run_date musí být due');
        $this->assertNotContains($pausedId, $dueIds, 'Pozastavená šablona nesmí být due');
        $this->assertNotContains($futureId, $dueIds, 'Budoucí next_run_date nesmí být due');
    }
}
