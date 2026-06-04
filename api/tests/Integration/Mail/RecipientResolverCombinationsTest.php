<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Mail;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientEmailContactRepository;
use MyInvoice\Service\Mail\RecipientResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vyčerpávající kombinační matice resolveru (#86):
 *
 *   typ zprávy (documents/reminders/approvals)
 *   × kontakty klienta (žádné / s odpovídajícím účelem)
 *   × zakázka (bez / s e-maily)
 *   × režim zakázky (auto/append/replace)
 *
 * = 24 kanonických kombinací v testMatrix + cílené testy interakcí: účely
 * e-mailů zakázky × kontakty × režimy, role cc/bcc, communication-only
 * kontakt, neodpovídající účel, dedup napříč zdroji, TO-empty fallback.
 *
 * Adresy: hlavni@x = main_email, kontakt@x = kontakt klienta s testovaným
 * účelem (role to), zakazka@x = e-mail zakázky bez omezení účelů.
 */
#[Group('integration')]
final class RecipientResolverCombinationsTest extends TestCase
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

    private const MAIN    = 'hlavni@example.cz';
    private const CONTACT = 'kontakt@example.cz';
    private const PROJECT = 'zakazka@example.cz';

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

    private function client(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'Matrix klient', 'Ulice 1', 'Praha', '11000', $this->czId, self::MAIN, $this->currencyId]);
        $id = (int) $pdo->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    /** @param list<array{0:string,1:?string}> $emails [e-mail, usages JSON|null] */
    private function project(int $clientId, array $emails, string $mode): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO projects (client_id, name, currency_id, billing_emails_mode) VALUES (?, ?, ?, ?)')
            ->execute([$clientId, 'Matrix zakázka', $this->currencyId, $mode]);
        $id = (int) $pdo->lastInsertId();
        $this->projectIds[] = $id;
        foreach ($emails as $i => [$em, $usages]) {
            $pdo->prepare('INSERT INTO project_billing_emails (project_id, email, position, usages) VALUES (?, ?, ?, ?)')
                ->execute([$id, $em, $i + 1, $usages]);
        }
        return $id;
    }

    /** @param list<array<string,mixed>> $contacts */
    private function setContacts(int $clientId, array $contacts): void
    {
        $this->contacts->replaceForClient($clientId, $this->supplierId, $contacts);
    }

    /** @return array<string,mixed> */
    private function invoice(int $clientId, ?int $projectId): array
    {
        return ['client_id' => $clientId, 'client_main_email' => self::MAIN, 'project_id' => $projectId];
    }

    // ── 24-řádková kanonická matice ──────────────────────────────────────

    /**
     * @return iterable<string, array{string, bool, ?string, list<string>}>
     *         [typ, máKontaktSDanýmÚčelem, režimZakázky|null(=bez zakázky), očekávané TO]
     */
    public static function matrixCases(): iterable
    {
        $m = self::MAIN; $c = self::CONTACT; $p = self::PROJECT;

        // ── BEZ kontaktů (legacy chování — musí být bit-perfect jako před #86) ──
        // documents/reminders: main + project (append); approvals: project NEBO main.
        foreach (['documents', 'reminders'] as $t) {
            yield "$t/bez kontaktů/bez zakázky"   => [$t, false, null,      [$m]];
            yield "$t/bez kontaktů/auto"          => [$t, false, 'auto',    [$m, $p]];
            yield "$t/bez kontaktů/append"        => [$t, false, 'append',  [$m, $p]];
            yield "$t/bez kontaktů/replace"       => [$t, false, 'replace', [$p]];
        }
        yield "approvals/bez kontaktů/bez zakázky" => ['approvals', false, null,      [$m]];
        yield "approvals/bez kontaktů/auto"        => ['approvals', false, 'auto',    [$p]];      // legacy: replace
        yield "approvals/bez kontaktů/append"      => ['approvals', false, 'append',  [$m, $p]];  // explicitní append mění legacy
        yield "approvals/bez kontaktů/replace"     => ['approvals', false, 'replace', [$p]];

        // ── S kontaktem s odpovídajícím účelem (main_email se už NEpřidává) ──
        foreach (['documents', 'reminders', 'approvals'] as $t) {
            yield "$t/kontakt/bez zakázky" => [$t, true, null,      [$c]];
            yield "$t/kontakt/auto"        => [$t, true, 'auto',    [$c, $p]];
            yield "$t/kontakt/append"      => [$t, true, 'append',  [$c, $p]];
            yield "$t/kontakt/replace"     => [$t, true, 'replace', [$p]];
        }
    }

    /** @param list<string> $expectedTo */
    #[DataProvider('matrixCases')]
    public function testMatrix(string $type, bool $withContact, ?string $mode, array $expectedTo): void
    {
        $clientId = $this->client();
        if ($withContact) {
            $this->setContacts($clientId, [
                ['email' => self::CONTACT, 'usages' => [['usage' => $type, 'recipient' => 'to']]],
            ]);
        }
        $projectId = $mode !== null
            ? $this->project($clientId, [[self::PROJECT, null]], $mode)
            : null;

        $r = $this->resolver->resolve($type, $this->invoice($clientId, $projectId));

        self::assertSame($expectedTo, $r['to'], "TO pro {$type}/kontakt=" . ($withContact ? '1' : '0') . '/mode=' . ($mode ?? '—'));
        self::assertSame([], $r['cc']);
        self::assertSame([], $r['bcc']);
    }

    // ── Interakce: účely e-mailů zakázky × kontakty × režimy ────────────

    public function testProjectUsageFilterCombinesWithContacts(): void
    {
        // Kontakt Doklady + e-mail zakázky omezený jen na Upomínky:
        //   documents → kontakt (zakázka odfiltrována)
        //   reminders (fallback na documents kontakty) → kontakt + zakázka
        $c = $this->client();
        $p = $this->project($c, [[self::PROJECT, '["reminders"]']], 'auto');
        $this->setContacts($c, [
            ['email' => self::CONTACT, 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
        ]);

        $docs = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame([self::CONTACT], $docs['to']);

        $rem = $this->resolver->resolve(RecipientResolver::TYPE_REMINDERS, $this->invoice($c, $p));
        self::assertSame([self::CONTACT, self::PROJECT], $rem['to']);
    }

    public function testProjectUsageFilterInReplaceMode(): void
    {
        // Replace s e-mailem omezeným na documents: documents → jen zakázka;
        // reminders → zakázka pro daný typ nemá e-maily → fallback na kontakty/main.
        $c = $this->client();
        $p = $this->project($c, [[self::PROJECT, '["documents"]']], 'replace');
        $this->setContacts($c, [
            ['email' => self::CONTACT, 'usages' => [['usage' => 'reminders', 'recipient' => 'to']]],
        ]);

        $docs = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame([self::PROJECT], $docs['to']);

        $rem = $this->resolver->resolve(RecipientResolver::TYPE_REMINDERS, $this->invoice($c, $p));
        self::assertSame([self::CONTACT], $rem['to'], 'Replace bez relevantních e-mailů zakázky → kontakty');
    }

    public function testProjectUsageFilterLegacyApprovalAuto(): void
    {
        // Legacy approval (bez kontaktů, auto): zakázka má e-mail jen pro documents
        // → pro approvals se chová jako zakázka bez e-mailů → main_email.
        $c = $this->client();
        $p = $this->project($c, [[self::PROJECT, '["documents"]']], 'auto');

        $r = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c, $p));
        self::assertSame([self::MAIN], $r['to']);
    }

    public function testMultipleProjectEmailsMixedUsages(): void
    {
        $c = $this->client();
        $p = $this->project($c, [
            ['vse@example.cz', null],                      // všechny typy
            ['jen-dokl@example.cz', '["documents"]'],
            ['dokl-upom@example.cz', '["documents","reminders"]'],
        ], 'auto');

        $docs = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame([self::MAIN, 'vse@example.cz', 'jen-dokl@example.cz', 'dokl-upom@example.cz'], $docs['to']);

        $rem = $this->resolver->resolve(RecipientResolver::TYPE_REMINDERS, $this->invoice($c, $p));
        self::assertSame([self::MAIN, 'vse@example.cz', 'dokl-upom@example.cz'], $rem['to']);

        $app = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c, $p));
        self::assertSame(['vse@example.cz'], $app['to'], 'Approval legacy replace bere jen e-maily platné pro approvals');
    }

    // ── Role cc/bcc v kombinacích ────────────────────────────────────────

    public function testCcOnlyContactFallsBackMainToTo(): void
    {
        // „Kopie účtárně, hlavní příjemce zůstává jednatel": kontakt jen cc
        // → TO nesmí zůstat prázdné, doplní se main_email.
        $c = $this->client();
        $this->setContacts($c, [
            ['email' => self::CONTACT, 'usages' => [['usage' => 'documents', 'recipient' => 'cc']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, null));
        self::assertSame([self::MAIN], $r['to']);
        self::assertSame([self::CONTACT], $r['cc']);
        self::assertSame('main_email', $r['resolved'][0]['source']);
    }

    public function testCcContactWithProjectEmailProjectIsTo(): void
    {
        // Kontakt cc + e-mail zakázky: TO = zakázka, CC = kontakt (žádný main fallback).
        $c = $this->client();
        $p = $this->project($c, [[self::PROJECT, null]], 'auto');
        $this->setContacts($c, [
            ['email' => self::CONTACT, 'usages' => [['usage' => 'documents', 'recipient' => 'cc']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame([self::PROJECT], $r['to']);
        self::assertSame([self::CONTACT], $r['cc']);
    }

    public function testMixedRolesAcrossContactsAndProject(): void
    {
        $c = $this->client();
        $p = $this->project($c, [[self::PROJECT, null]], 'auto');
        $this->setContacts($c, [
            ['email' => 'to@example.cz',  'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
            ['email' => 'cc@example.cz',  'usages' => [['usage' => 'documents', 'recipient' => 'cc']]],
            ['email' => 'bcc@example.cz', 'usages' => [['usage' => 'documents', 'recipient' => 'bcc']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame(['to@example.cz', self::PROJECT], $r['to']);
        self::assertSame(['cc@example.cz'], $r['cc']);
        self::assertSame(['bcc@example.cz'], $r['bcc']);
    }

    public function testPerUsageRolesDiffer(): void
    {
        // Jeden kontakt: documents jako to, reminders jako cc — role je per účel.
        $c = $this->client();
        $this->setContacts($c, [
            ['email' => self::CONTACT, 'usages' => [
                ['usage' => 'documents', 'recipient' => 'to'],
                ['usage' => 'reminders', 'recipient' => 'cc'],
            ]],
        ]);

        $docs = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, null));
        self::assertSame([self::CONTACT], $docs['to']);

        $rem = $this->resolver->resolve(RecipientResolver::TYPE_REMINDERS, $this->invoice($c, null));
        self::assertSame([self::CONTACT], $rem['cc']);
        self::assertSame([self::MAIN], $rem['to'], 'cc-only pro upomínky → main fallback do TO');
    }

    // ── Účel communication a neodpovídající účely ────────────────────────

    public function testCommunicationOnlyContactDoesNotAffectSending(): void
    {
        // Kontakt jen pro komunikaci → odesílání dokladů/upomínek/schvalování
        // se chová jako bez kontaktů (legacy).
        $c = $this->client();
        $p = $this->project($c, [[self::PROJECT, null]], 'auto');
        $this->setContacts($c, [
            ['email' => self::CONTACT, 'usages' => [['usage' => 'communication', 'recipient' => 'to']]],
        ]);

        foreach ([RecipientResolver::TYPE_DOCUMENTS, RecipientResolver::TYPE_REMINDERS] as $t) {
            $r = $this->resolver->resolve($t, $this->invoice($c, $p));
            self::assertSame([self::MAIN, self::PROJECT], $r['to'], "communication kontakt nesmí ovlivnit {$t}");
        }
        $app = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c, $p));
        self::assertSame([self::PROJECT], $app['to']);
    }

    public function testNonMatchingUsageFallsBackToLegacy(): void
    {
        // Kontakt jen pro approvals → documents jede legacy (main + zakázka).
        $c = $this->client();
        $p = $this->project($c, [[self::PROJECT, null]], 'auto');
        $this->setContacts($c, [
            ['email' => self::CONTACT, 'usages' => [['usage' => 'approvals', 'recipient' => 'to']]],
        ]);

        $docs = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame([self::MAIN, self::PROJECT], $docs['to']);

        $app = $this->resolver->resolve(RecipientResolver::TYPE_APPROVALS, $this->invoice($c, $p));
        self::assertSame([self::CONTACT, self::PROJECT], $app['to']);
    }

    // ── Dedup napříč zdroji ──────────────────────────────────────────────

    public function testDedupContactEqualsProjectEmail(): void
    {
        // Stejná adresa jako kontakt i e-mail zakázky → jednou, zdroj = kontakt (první výskyt).
        $c = $this->client();
        $p = $this->project($c, [[self::CONTACT, null]], 'auto');
        $this->setContacts($c, [
            ['email' => self::CONTACT, 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame([self::CONTACT], $r['to']);
        self::assertCount(1, $r['resolved']);
        self::assertSame('contact', $r['resolved'][0]['source']);
    }

    public function testDedupContactEqualsMainEmail(): void
    {
        // main_email přidaný i jako kontakt (tlačítko „Převzít hlavní e-mail")
        // → jednou, jako kontakt; žádný extra main fallback.
        $c = $this->client();
        $this->setContacts($c, [
            ['email' => self::MAIN, 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
            ['email' => self::CONTACT, 'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, null));
        self::assertSame([self::MAIN, self::CONTACT], $r['to']);
        self::assertSame(['contact', 'contact'], array_column($r['resolved'], 'source'));
    }

    public function testDedupCcContactAgainstProjectToWins(): void
    {
        // Stejná adresa: kontakt cc + e-mail zakázky (to) → vyhrává silnější role TO.
        $c = $this->client();
        $p = $this->project($c, [[self::CONTACT, null]], 'auto');
        $this->setContacts($c, [
            ['email' => self::CONTACT, 'usages' => [['usage' => 'documents', 'recipient' => 'cc']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, $p));
        self::assertSame([self::CONTACT], $r['to']);
        self::assertSame([], $r['cc']);
    }

    // ── Více kontaktů se stejným účelem (pořadí dle sort_order) ─────────

    public function testMultipleContactsStableOrder(): void
    {
        $c = $this->client();
        $this->setContacts($c, [
            ['email' => 'prvni@example.cz',  'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
            ['email' => 'druhy@example.cz',  'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
            ['email' => 'treti@example.cz',  'usages' => [['usage' => 'documents', 'recipient' => 'to']]],
        ]);

        $r = $this->resolver->resolve(RecipientResolver::TYPE_DOCUMENTS, $this->invoice($c, null));
        self::assertSame(['prvni@example.cz', 'druhy@example.cz', 'treti@example.cz'], $r['to']);
    }
}
