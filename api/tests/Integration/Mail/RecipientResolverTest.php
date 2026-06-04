<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Mail;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientEmailContactRepository;
use MyInvoice\Service\Mail\RecipientResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Jednotný resolver příjemců (#86) + CRUD kontaktů.
 *
 * Klíčový kontrakt: BEZ kontaktů se resolver chová BIT-PERFECT jako dosavadních
 * šest resolveRecipients() implementací (documents/reminders: main_email +
 * project_billing_emails append; approvals: project NEBO main, nikdy nesměšovat).
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class RecipientResolverTest extends TestCase
{
    private Connection $db;
    private RecipientResolver $resolver;
    private ClientEmailContactRepository $contacts;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $clientIds = [];
    /** @var int[] */
    private array $projectIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->resolver = $container->get(RecipientResolver::class);
            $this->contacts = $container->get(ClientEmailContactRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->projectIds as $id) {
            $pdo->prepare('DELETE FROM project_billing_emails WHERE project_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM client_email_contacts WHERE client_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function client(string $mainEmail = 'hlavni@example.cz'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'RR Test klient', 'Ulice 1', 'Praha', '11000', $this->czId, $mainEmail, $this->currencyId]);
        $id = (int) $pdo->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    /** @param list<string|array{0:string,1:?string}> $billingEmails e-mail, nebo [e-mail, usages JSON|null] */
    private function project(int $clientId, array $billingEmails = [], string $mode = 'auto'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO projects (client_id, name, currency_id, billing_emails_mode) VALUES (?, ?, ?, ?)'
        )->execute([$clientId, 'RR Test zakázka', $this->currencyId, $mode]);
        $id = (int) $pdo->lastInsertId();
        $this->projectIds[] = $id;
        foreach ($billingEmails as $i => $entry) {
            [$em, $usages] = is_array($entry) ? $entry : [$entry, null];
            $pdo->prepare('INSERT INTO project_billing_emails (project_id, email, position, usages) VALUES (?, ?, ?, ?)')
                ->execute([$id, $em, $i + 1, $usages]);
        }
        return $id;
    }

    /** @return array<string,mixed> pseudo-invoice row pro resolver */
    private function invoice(int $clientId, ?int $projectId, string $mainEmail = 'hlavni@example.cz'): array
    {
        return ['client_id' => $clientId, 'client_main_email' => $mainEmail, 'project_id' => $projectId];
    }

    /** @param list<array<string,mixed>> $contacts */
    private function setContacts(int $clientId, array $contacts): void
    {
        $this->contacts->replaceForClient($clientId, $this->supplierId, $contacts);
    }

    // ── legacy kompatibilita (bez kontaktů) ──────────────────────────────

    public function testLegacyDocumentsMainPlusProjectAppend(): void
    {
        $c = $this->client();
        $p = $this->project($c, ['ucto@example.cz', 'pm@example.cz']);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));

        self::assertSame(['hlavni@example.cz', 'ucto@example.cz', 'pm@example.cz'], $r['to']);
        self::assertSame([], $r['cc']);
        self::assertSame([], $r['bcc']);
    }

    public function testLegacyRemindersSameAsDocuments(): void
    {
        $c = $this->client();
        $p = $this->project($c, ['ucto@example.cz']);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_REMINDERS, $this->invoice($c, $p));
        self::assertSame(['hlavni@example.cz', 'ucto@example.cz'], $r['to']);
    }

    public function testLegacyApprovalsProjectReplacesMain(): void
    {
        $c = $this->client();
        $p = $this->project($c, ['pm@example.cz']);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c, $p));
        self::assertSame(['pm@example.cz'], $r['to'], 'Approval: project emails NAHRAZUJÍ main_email (legacy)');
    }

    public function testLegacyApprovalsFallbackToMainWithoutProjectEmails(): void
    {
        $c = $this->client();
        $p = $this->project($c, []);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c, $p));
        self::assertSame(['hlavni@example.cz'], $r['to']);
    }

    public function testLegacyWithoutProject(): void
    {
        $c = $this->client();
        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, null));
        self::assertSame(['hlavni@example.cz'], $r['to']);
    }

    public function testLegacyDedupMainEqualsProjectEmail(): void
    {
        $c = $this->client();
        $p = $this->project($c, ['hlavni@example.cz', 'ucto@example.cz']);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame(['hlavni@example.cz', 'ucto@example.cz'], $r['to']);
    }

    // ── kontakty dle účelu ───────────────────────────────────────────────

    public function testDocumentsContactExcludesMainEmail(): void
    {
        $c = $this->client();
        $this->setContacts($c, [
            ['email' => 'fakturace@example.cz', 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, null));
        self::assertSame(['fakturace@example.cz'], $r['to'],
            'S kontaktem pro doklady se main_email už NEpřidává (hlavní požadavek #86)');
    }

    public function testCcAndBccBuckets(): void
    {
        $c = $this->client();
        $this->setContacts($c, [
            ['email' => 'fakturace@example.cz', 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
            ['email' => 'kopie@example.cz', 'usages' => [['usage' => 'documents', 'recipient' => 'cc']]],
            ['email' => 'archiv@example.cz', 'usages' => [['usage' => 'documents', 'recipient' => 'bcc']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, null));
        self::assertSame(['fakturace@example.cz'], $r['to']);
        self::assertSame(['kopie@example.cz'], $r['cc']);
        self::assertSame(['archiv@example.cz'], $r['bcc']);
    }

    public function testRemindersFallbackToDocumentsContacts(): void
    {
        $c = $this->client();
        $this->setContacts($c, [
            ['email' => 'fakturace@example.cz', 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_REMINDERS, $this->invoice($c, null));
        self::assertSame(['fakturace@example.cz'], $r['to']);
    }

    public function testRemindersOwnContactsWinOverDocuments(): void
    {
        $c = $this->client();
        $this->setContacts($c, [
            ['email' => 'fakturace@example.cz', 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
            ['email' => 'vymahani@example.cz', 'usages' => [['usage' => 'reminders', 'recipient' => 'to']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_REMINDERS, $this->invoice($c, null));
        self::assertSame(['vymahani@example.cz'], $r['to']);
    }

    public function testInactiveContactIgnored(): void
    {
        $c = $this->client();
        $this->setContacts($c, [
            ['email' => 'stary@example.cz', 'is_active' => false, 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, null));
        self::assertSame(['hlavni@example.cz'], $r['to'], 'Neaktivní kontakt → legacy fallback na main_email');
    }

    public function testContactsPlusProjectAppendInAutoMode(): void
    {
        $c = $this->client();
        $p = $this->project($c, ['ucto@example.cz']);
        $this->setContacts($c, [
            ['email' => 'fakturace@example.cz', 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame(['fakturace@example.cz', 'ucto@example.cz'], $r['to']);
    }

    public function testProjectReplaceModeOverridesContacts(): void
    {
        $c = $this->client();
        $p = $this->project($c, ['jen-tahle@example.cz'], mode: 'replace');
        $this->setContacts($c, [
            ['email' => 'fakturace@example.cz', 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame(['jen-tahle@example.cz'], $r['to']);
    }

    public function testProjectAppendModeForApprovalsLegacy(): void
    {
        // Explicitní append mění legacy approval sémantiku: main + project (uživatel si vybral).
        $c = $this->client();
        $p = $this->project($c, ['pm@example.cz'], mode: 'append');

        $r = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c, $p));
        self::assertSame(['hlavni@example.cz', 'pm@example.cz'], $r['to']);
    }

    public function testDedupPriorityToBeatsCc(): void
    {
        $c = $this->client();
        $this->setContacts($c, [
            ['email' => 'duo@example.cz', 'usages' => [['usage' => 'documents', 'recipient' => 'cc']]],
            ['email' => 'Duo@Example.cz', 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, null));
        self::assertCount(1, array_merge($r['to'], $r['cc'], $r['bcc']), 'Case-insensitive dedup');
        self::assertCount(1, $r['to'], 'Při duplicitě vyhrává silnější role (to > cc)');
    }

    public function testResolvedProvenance(): void
    {
        $c = $this->client();
        $p = $this->project($c, ['ucto@example.cz']);
        $this->setContacts($c, [
            ['email' => 'fakturace@example.cz', 'label' => 'účtárna', 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        $sources = array_column($r['resolved'], 'source');
        self::assertSame(['contact', 'project'], $sources);
        self::assertSame('účtárna', $r['resolved'][0]['label']);
        self::assertSame('documents', $r['resolved'][0]['usage']);
    }

    // ── účely e-mailů zakázky (#86 follow-up) ────────────────────────────

    public function testProjectEmailUsagesFilterByType(): void
    {
        $c = $this->client();
        $p = $this->project($c, [
            ['jen-doklady@example.cz', '["documents"]'],
            ['jen-upominky@example.cz', '["reminders"]'],
            ['vse@example.cz', null],  // NULL = všechny typy (legacy default)
        ]);

        $docs = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame(['hlavni@example.cz', 'jen-doklady@example.cz', 'vse@example.cz'], $docs['to']);

        $rem = $this->resolver->resolve(RecipientResolver::TYPE_REMINDERS, $this->invoice($c, $p));
        self::assertSame(['hlavni@example.cz', 'jen-upominky@example.cz', 'vse@example.cz'], $rem['to']);
    }

    public function testProjectEmailUsagesApprovalsLegacyReplace(): void
    {
        // Approval legacy replace bere jen e-maily s účelem approvals (nebo bez omezení).
        $c = $this->client();
        $p = $this->project($c, [
            ['jen-doklady@example.cz', '["documents"]'],
            ['pm@example.cz', '["approvals"]'],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c, $p));
        self::assertSame(['pm@example.cz'], $r['to']);
    }

    public function testProjectEmailUsagesEmptyArrayMeansAll(): void
    {
        $c = $this->client();
        $p = $this->project($c, [['vse@example.cz', '[]']]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_REMINDERS, $this->invoice($c, $p));
        self::assertContains('vse@example.cz', $r['to']);
    }

    // ── repository validace ──────────────────────────────────────────────

    public function testRepositoryRoundTrip(): void
    {
        $c = $this->client();
        $saved = $this->contacts->replaceForClient($c, $this->supplierId, [
            ['email' => 'a@example.cz', 'label' => 'A', 'contact_name' => 'Pan A',
             'usages' => [['usage' => 'documents', 'recipient' => 'to'], ['usage' => 'reminders', 'recipient' => 'cc']]],
            ['email' => 'b@example.cz', 'usages' => []],
        ]);

        self::assertCount(2, $saved);
        self::assertSame('a@example.cz', $saved[0]['email']);
        self::assertSame('Pan A', $saved[0]['contact_name']);
        self::assertSame([['usage' => 'documents', 'recipient' => 'to'], ['usage' => 'reminders', 'recipient' => 'cc']], $saved[0]['usages']);
        self::assertTrue($saved[1]['is_active']);
        self::assertSame([], $saved[1]['usages']);
    }

    public function testRepositoryRejectsInvalidEmail(): void
    {
        $c = $this->client();
        $this->expectException(\DomainException::class);
        $this->contacts->replaceForClient($c, $this->supplierId, [['email' => 'neni-email', 'usages' => []]]);
    }

    public function testRepositoryRejectsInvalidUsage(): void
    {
        $c = $this->client();
        $this->expectException(\DomainException::class);
        $this->contacts->replaceForClient($c, $this->supplierId, [
            ['email' => 'a@example.cz', 'usages' => [['usage' => 'marketing', 'recipient' => 'to']]],
        ]);
    }

    public function testRepositoryRejectsForeignSupplier(): void
    {
        $c = $this->client();
        $this->expectException(\DomainException::class);
        $this->contacts->replaceForClient($c, $this->supplierId + 999, [['email' => 'a@example.cz', 'usages' => []]]);
    }

    public function testRepositoryEnforcesLimit(): void
    {
        $c = $this->client();
        $contacts = [];
        for ($i = 0; $i <= ClientEmailContactRepository::MAX_CONTACTS; $i++) {
            $contacts[] = ['email' => "k{$i}@example.cz", 'usages' => []];
        }
        $this->expectException(\DomainException::class);
        $this->contacts->replaceForClient($c, $this->supplierId, $contacts);
    }
}
