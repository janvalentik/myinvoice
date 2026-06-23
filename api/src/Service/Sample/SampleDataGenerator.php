<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;

/**
 * Generuje testovací sample data — 5 klientů, 8 zakázek, 20 faktur, 4 dobropisy,
 * 4 dodavatelé, 12 přijatých faktur, 2 pravidelné fakturace a kniha jízd
 * (1 firemní auto, 15 jízd, 6 tankování).
 * Sdílená logika pro `bin/sample.php` (CLI) i `SetupSampleAction` (HTTP wizard).
 *
 * Vrací: ['clients' => 5, 'projects' => 8, 'invoices' => 20, 'credit_notes' => 4,
 *         'vendors' => 4, 'purchase_invoices' => 12, 'recurring' => 2,
 *         'cars' => 1, 'trips' => 15, 'fuelings' => 6]
 */
final class SampleDataGenerator
{
    public function __construct(
        private readonly Connection $db,
        private readonly StatsRecomputer $stats,
        private readonly RecurringTemplateRepository $recurring,
    ) {}

    /**
     * @return array{clients:int, projects:int, invoices:int, credit_notes:int, vendors:int, purchase_invoices:int, recurring:int, cars:int, trips:int, fuelings:int}
     */
    public function generate(int $supplierId, int $adminUserId): array
    {
        $pdo = $this->db->pdo();

        // Guard: sample data se generují JEN do prázdné DB. Bez této pojistky
        // šel `bin/sample.php` spustit i nad existujícími daty → duplicitní klienti/
        // faktury a pád na UNIQUE (cars.registration). HTTP wizard guard má taky
        // (SetupSampleAction), tady je sdílená pojistka pro CLI i wizard.
        $guard = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM clients          WHERE supplier_id = ?)
                  + (SELECT COUNT(*) FROM invoices         WHERE supplier_id = ?)
                  + (SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?)'
        );
        $guard->execute([$supplierId, $supplierId, $supplierId]);
        if ((int) $guard->fetchColumn() > 0) {
            throw new \RuntimeException(
                'Ukázková data nelze vygenerovat — pro tohoto dodavatele už existují klienti nebo doklady. '
                . 'Nejdřív je odeberte (Nastavení → Odebrat ukázková data) nebo spusťte `php api/bin/reset.php`.'
            );
        }

        // Kořenové entity vytvořené generátorem — na konci se zapíšou do
        // sample_data_entries, ať je lze později přesně odebrat (issue #162).
        $tracked = [];
        $track = static function (string $type, int $id) use (&$tracked): void {
            if ($id > 0) $tracked[] = [$type, $id];
        };

        $resolveCurrency = function (string $code) use ($pdo, $supplierId): int {
            $stmt = $pdo->prepare(
                'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
            );
            $stmt->execute([$supplierId, strtoupper($code)]);
            $id = (int) $stmt->fetchColumn();
            if ($id === 0) {
                throw new \RuntimeException("Currency $code nenalezena pro supplier #$supplierId");
            }
            return $id;
        };
        $czkId = $resolveCurrency('CZK');
        $eurId = $resolveCurrency('EUR');

        // Vše v jedné transakci → při chybě (např. UNIQUE) se nic nezapíše a DB
        // nezůstane v polovičním stavu. Stats recompute běží AŽ po commitu, protože
        // StatsRecomputer si otevírá vlastní transakci (vnořené PDO transakce nejdou).
        $pdo->beginTransaction();
        try {

        // RC flag (index 8) daňově smysluplně: tuzemští klienti BEZ reverse charge
        // (tuzemský RC §92a na IT služby neexistuje), EU klienti s DIČ (SK, DE)
        // S reverse charge — poskytnutí služby do JČS (ř.21 DPHDP3, kód 22, SHV).
        $clients = [
            ['ACME Czech s.r.o.',     '12345678', 'CZ12345678', 'Václavské náměstí 1',  '11000', 'Praha 1',  'CZ', 'invoice@acme.cz',     0, 'cs', $czkId, 'CZK'],
            ['BlueWave Digital a.s.', '87654321', 'CZ87654321', 'Husova 23',            '60200', 'Brno',     'CZ', 'finance@bluewave.cz', 0, 'cs', $czkId, 'CZK'],
            ['Bratislava Soft s.r.o.','46782931', 'SK2023456789','Mlynská 5',            '81101', 'Bratislava','SK', 'fakturace@bsoft.sk',  1, 'cs', $eurId, 'EUR'],
            ['Studio Fialka',         null,       null,         'Nádražní 7',           '70030', 'Ostrava',  'CZ', 'jana@fialka.cz',      0, 'cs', $czkId, 'CZK'],
            ['NorthLight GmbH',       null,       'DE123456789','Hauptstrasse 12',      '10115', 'Berlin',   'DE', 'billing@northlight.de', 1, 'en', $eurId, 'EUR'],
        ];

        $clientIds = [];
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        foreach ($clients as [$company, $ic, $dic, $street, $zip, $city, $iso2, $email, $rc, $lang, $currencyId, $currencyCode]) {
            $stmtCountry = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
            $stmtCountry->execute([$iso2]);
            $countryId = (int) $stmtCountry->fetchColumn() ?: $czId;
            $stmt = $pdo->prepare(
                'INSERT INTO clients (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email,
                                      language, currency_default_id, reverse_charge)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$supplierId, $company, $ic, $dic, $street, $city, $zip, $countryId, $email, $lang, $currencyId, $rc]);
            $cid = (int) $pdo->lastInsertId();
            $clientIds[] = $cid;
            $track('client', $cid);
        }

        $projects = [
            [0, 'Údržba webu 2026',         '15000', '0102/2026', 14, 1500, $czkId, 'CZK'],
            [0, 'Refactor backendu Q2',     '15001', '0103/2026', 14, 1800, $czkId, 'CZK'],
            [1, 'Mobile app iOS',           '4523',  '2026/M-12', 30, 1500, $czkId, 'CZK'],
            [1, 'SEO konzultace',           null,    null,        14, 1500, $czkId, 'CZK'],
            [2, 'Cloud migration',          'BSF-7', '0204/2026',  7, 60,   $eurId, 'EUR'],
            [3, 'Tisk + grafika',           null,    null,        14, 1200, $czkId, 'CZK'],
            [4, 'API integration consulting', 'NL-PROJ-A', null,  21, 90,   $eurId, 'EUR'],
            [4, 'Annual support contract',    null,  'NL-2026',   30, 80,   $eurId, 'EUR'],
        ];
        $projectIds = [];
        foreach ($projects as [$ci, $name, $projNum, $contractNum, $due, $rate, $currencyId, $currencyCode]) {
            $stmt = $pdo->prepare(
                'INSERT INTO projects (client_id, name, payment_due_days, project_number, contract_number,
                                       hourly_rate, currency_id, status)
                 VALUES (?,?,?,?,?,?,?,"active")'
            );
            $stmt->execute([$clientIds[$ci], $name, $due, $projNum, $contractNum, $rate, $currencyId]);
            $projId = (int) $pdo->lastInsertId();
            $projectIds[] = $projId;
            $track('project', $projId);
        }

        $today  = new \DateTimeImmutable('today');
        $thisMonth = $today->format('Y-m');
        $prevMonth = $today->modify('-1 month')->format('Y-m');

        $stdVat = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-21' LIMIT 1")->fetchColumn();
        $lowVat = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-12' LIMIT 1")->fetchColumn();
        $rcVat  = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-RC' LIMIT 1")->fetchColumn();

        $invoices = [];
        for ($i = 0; $i < 20; $i++) {
            $month = $i < 10 ? $prevMonth : $thisMonth;
            $clientIdx = $i % 5;
            $clientCurrencyId = $clients[$clientIdx][10];
            $clientCurrency   = $clients[$clientIdx][11];
            $clientReverseCharge = $clients[$clientIdx][8];
            $compatibleProjects = array_filter($projects, fn ($p, $k) => $p[0] === $clientIdx, ARRAY_FILTER_USE_BOTH);
            $compatibleProjectKeys = array_keys($compatibleProjects);
            $projKey = $compatibleProjectKeys[$i % count($compatibleProjectKeys)] ?? null;
            $projectId = $projKey !== null ? $projectIds[$projKey] : null;

            $day = ($i * 3) % 28 + 1;
            $issueDate = "$month-" . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
            if ($issueDate > $today->format('Y-m-d')) $issueDate = $today->format('Y-m-d');
            $taxDate = $issueDate;
            $dueDate = (new \DateTimeImmutable($issueDate))->modify('+14 days')->format('Y-m-d');

            $period = str_replace('-', '', $month);
            $vs = $this->nextVarsymbol($pdo, $supplierId, 'invoice', $period);

            $status = match (true) {
                $i < 6  => 'paid',
                $i < 14 => 'sent',
                default => 'issued',
            };

            $vatRate = $clientReverseCharge ? $rcVat : $stdVat;
            $vatPct  = $clientReverseCharge ? 0 : 21;

            // Exchange rate pro non-CZK faktury — hardcoded ~25 CZK/EUR (rough CNB average).
            // Bez něj by ranking v Top klientech počítal EUR jako 1:1 CZK (1000 EUR ranked
            // jako 1000 Kč) — viz commit db85305 a issue ohledně NorthLight GmbH.
            // Pozn.: invoices tabulka NEMÁ exchange_rate_source (jen purchase_invoices má).
            $exchangeRate = $clientCurrency === 'CZK' ? null : 25.0;
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                    (supplier_id, varsymbol, invoice_type, client_id, project_id, issue_date, tax_date, due_date,
                     currency_id, exchange_rate, exchange_rate_date,
                     reverse_charge, language, vat_classification_code, total_without_vat, total_vat, total_with_vat,
                     status, sent_at, paid_at, created_by)
                 VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?)'
            );
            $sentAt = in_array($status, ['sent', 'paid'], true) ? $issueDate . ' 14:00:00' : null;
            $paidAt = $status === 'paid'
                ? (new \DateTimeImmutable($issueDate))->modify('+' . random_int(3, 12) . ' days')->format('Y-m-d')
                : null;
            $stmt->execute([
                $supplierId, $vs, $clientIds[$clientIdx], $projectId, $issueDate, $taxDate, $dueDate,
                $clientCurrencyId, $exchangeRate, $exchangeRate !== null ? $issueDate : null,
                $clientReverseCharge ? 1 : 0,
                $clients[$clientIdx][9],
                // EU RC = poskytnutí služby do JČS → kód 22 (ř.21 DPHDP3 + SHV);
                // tuzemské nechávat bez kódu (fallback dle sazby → ř.1, KH A.4/A.5).
                $clientReverseCharge ? '22' : null,
                $status, $sentAt, $paidAt, $adminUserId,
            ]);
            $invId = (int) $pdo->lastInsertId();
            $track('invoice', $invId);
            $invoices[] = ['id' => $invId, 'vs' => $vs, 'currency' => $clientCurrency, 'currency_id' => $clientCurrencyId, 'rc' => $clientReverseCharge];

            $itemCount = random_int(1, 3);
            $totalBase = 0; $totalVat = 0;
            for ($k = 0; $k < $itemCount; $k++) {
                $hours = random_int(2, 40);
                $rate = $clientCurrency === 'EUR' ? random_int(60, 100) : random_int(1200, 2000);
                $base = $hours * $rate;
                $vatAmt = round($base * $vatPct / 100, 2); // RC má vatPct 0 → daň 0
                $totalBase += $base;
                $totalVat  += $vatAmt;

                $itemMonth = (new \DateTimeImmutable($issueDate))->format('n/Y');
                $description = match ($k) {
                    0 => "Konzultace $itemMonth",
                    1 => "Vývoj — sprint $itemMonth",
                    default => "Údržba $itemMonth",
                };
                $pdo->prepare(
                    'INSERT INTO invoice_items
                        (invoice_id, description, quantity, unit, unit_price_without_vat,
                         vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                     VALUES (?,?,?,"h",?,?,?,?,?,?,?)'
                )->execute([
                    $invId, $description, $hours, $rate, $vatRate, $vatPct, $base, $vatAmt, $base + $vatAmt, $k,
                ]);
            }
            $totalWithVat = $totalBase + $totalVat;
            $pdo->prepare(
                'UPDATE invoices SET total_without_vat = ?, total_vat = ?, total_with_vat = ? WHERE id = ?'
            )->execute([$totalBase, $totalVat, $totalWithVat, $invId]);
        }

        // Dobropisy (4 ks k prvním 4 fakturám)
        $creditTargets = array_slice($invoices, 0, 4);
        foreach ($creditTargets as $parent) {
            $month = $thisMonth;
            $period = str_replace('-', '', $month);
            $vs = $this->nextVarsymbol($pdo, $supplierId, 'credit_note', $period);
            $issueDate = $today->modify('-' . random_int(0, 5) . ' days')->format('Y-m-d');

            $parentInv = $pdo->prepare(
                'SELECT i.*, cur.code AS currency
                   FROM invoices i
                   JOIN currencies cur ON cur.id = i.currency_id
                  WHERE i.id = ?'
            );
            $parentInv->execute([$parent['id']]);
            $p = $parentInv->fetch(PDO::FETCH_ASSOC);

            // Exchange rate kopírujeme z parent invoice (dobropis je její opak).
            // invoices tabulka NEMÁ exchange_rate_source.
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                    (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, project_id,
                     issue_date, tax_date, due_date, currency_id, exchange_rate, exchange_rate_date,
                     reverse_charge, language, vat_classification_code,
                     total_without_vat, total_vat, total_with_vat, status, sent_at, created_by)
                 VALUES (?, ?, "credit_note", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "sent", ?, ?)'
            );
            $stmt->execute([
                $supplierId, $vs, $p['id'], $p['client_id'], $p['project_id'],
                $issueDate, $issueDate, $issueDate,
                (int) $p['currency_id'],
                $p['exchange_rate'] ?? null,
                $p['exchange_rate'] !== null ? $issueDate : null,
                $p['reverse_charge'], $p['language'],
                $p['vat_classification_code'] ?? null, // dobropis dědí klasifikaci originálu
                -$p['total_without_vat'], -$p['total_vat'], -$p['total_with_vat'],
                $issueDate . ' 12:00:00', $adminUserId,
            ]);
            $cnId = (int) $pdo->lastInsertId();
            $track('credit_note', $cnId);

            $pdo->prepare(
                'INSERT INTO invoice_items
                    (invoice_id, description, quantity, unit, unit_price_without_vat,
                     vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?,?,-1,"ks",?,?,?,?,?,?,0)'
            )->execute([
                $cnId,
                "Dobropis k faktuře {$p['varsymbol']}",
                $p['total_without_vat'],
                $p['reverse_charge'] ? $rcVat : $stdVat,
                $p['reverse_charge'] ? 0 : 21,
                -$p['total_without_vat'], -$p['total_vat'], -$p['total_with_vat'],
            ]);
        }

        // ───── Dodavatelé (is_vendor=1, is_customer=0) ─────
        // Daňově smysluplné profily: US dodavatelé služeb = reverse charge
        // (samovyměření, kód 24 → ř.12 + mirror odpočet ř.43, jako reálný
        // GitHub/Anthropic doklad), tuzemští = česká DPH (kód 40/41, KH B.2/B.3).
        $vendors = [
            ['Anthropic, PBC',          null,        null,         '548 Market St #79290',  '94104', 'San Francisco', 'US', 'billing@anthropic.com', $eurId, 'EUR'],
            ['Microsoft Czech s.r.o.',  '47123737',  'CZ47123737', 'Vyskočilova 1561/4a',   '14000', 'Praha 4',       'CZ', 'fakturace@microsoft.cz', $czkId, 'CZK'],
            ['GitHub, Inc.',            null,        null,         '88 Colin P Kelly Jr St', '94107', 'San Francisco', 'US', 'billing@github.com',    $eurId, 'EUR'],
            ['Office Pro s.r.o.',       '28765432',  'CZ28765432', 'Korunní 810/104',        '10100', 'Praha 10',     'CZ', 'fakturace@officepro.cz', $czkId, 'CZK'],
        ];
        // Per-vendor: RC flag + pool položek [popis, sazba %, klasifikační kód].
        // RC položky nesou nominální sazbu 21 s daní 0 (samovyměření dopočítají
        // až DPH výkazy z rate snapshotu — stejný model jako AI import / editor).
        $vendorItemPools = [
            'Anthropic, PBC' => ['rc' => true, 'items' => [
                ['Claude API kredity', 21, '24'],
                ['Claude Max — měsíční předplatné', 21, '24'],
            ]],
            'GitHub, Inc.' => ['rc' => true, 'items' => [
                ['GitHub Copilot — předplatné', 21, '24'],
                ['GitHub Team — roční plán', 21, '24'],
            ]],
            'Microsoft Czech s.r.o.' => ['rc' => false, 'items' => [
                ['Microsoft 365 Business Premium — licence', 21, '40'],
                ['Azure — cloud služby', 21, '40'],
            ]],
            'Office Pro s.r.o.' => ['rc' => false, 'items' => [
                ['Kancelářské potřeby', 21, '40'],
                ['Odborná literatura', 12, '41'],
                ['Papír a tonery do tiskárny', 21, '40'],
            ]],
        ];
        $vendorIds = [];
        $vendorMeta = [];
        foreach ($vendors as [$company, $ic, $dic, $street, $zip, $city, $iso2, $email, $currencyId, $currencyCode]) {
            $stmtCountry = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
            $stmtCountry->execute([$iso2]);
            $countryId = (int) $stmtCountry->fetchColumn() ?: $czId;
            $stmt = $pdo->prepare(
                'INSERT INTO clients (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email,
                                      language, currency_default_id, is_customer, is_vendor)
                 VALUES (?,?,?,?,?,?,?,?,?, "cs", ?, 0, 1)'
            );
            $stmt->execute([$supplierId, $company, $ic, $dic, $street, $city, $zip, $countryId, $email, $currencyId]);
            $vid = (int) $pdo->lastInsertId();
            $vendorIds[] = $vid;
            $track('vendor', $vid);
            $vendorMeta[] = [
                'id' => $vid, 'company' => $company, 'ic' => $ic, 'dic' => $dic,
                'street' => $street, 'zip' => $zip, 'city' => $city, 'iso2' => $iso2,
                'currency_id' => $currencyId, 'currency' => $currencyCode,
            ];
        }

        // ───── Přijaté faktury (12 ks rozprostřených přes posledních 6 měsíců) ─────
        $purchaseCount = 0;
        for ($i = 0; $i < 12; $i++) {
            $monthsBack = (int) floor($i / 2);
            $issueDt = $today->modify("-{$monthsBack} months")->modify('-' . ($i * 2) . ' days');
            if ($issueDt > $today) $issueDt = $today->modify('-1 day');
            $issueDate = $issueDt->format('Y-m-d');
            $taxDate   = $issueDate;
            $dueDate   = $issueDt->modify('+14 days')->format('Y-m-d');
            $receivedAt = $issueDt->modify('+2 days')->format('Y-m-d');

            $v = $vendorMeta[$i % count($vendorMeta)];
            $period = $issueDt->format('Ym');
            $vs = $this->nextPurchaseVarsymbol($pdo, $supplierId, $period);

            // Status: starší jsou paid, novější booked/received
            $status = match (true) {
                $monthsBack >= 3 => 'paid',
                $monthsBack >= 1 => 'booked',
                default          => 'received',
            };
            $bookedAt = in_array($status, ['booked', 'paid'], true) ? $issueDate . ' 14:00:00' : null;
            $paidAt   = $status === 'paid' ? $issueDt->modify('+' . random_int(3, 12) . ' days')->format('Y-m-d') : null;

            $vendorInvoiceNumber = sprintf('INV-%s-%04d', substr($period, 2), $i + 100);
            $vendorSnapshot = json_encode([
                'company_name' => $v['company'],
                'ic' => $v['ic'], 'dic' => $v['dic'],
                'street' => $v['street'], 'city' => $v['city'], 'zip' => $v['zip'],
                'country_iso2' => $v['iso2'],
            ], JSON_UNESCAPED_UNICODE);

            $exchangeRate = $v['currency'] === 'CZK' ? null : 25.0;
            $pool = $vendorItemPools[$v['company']];
            $isRc = $pool['rc'];

            $stmt = $pdo->prepare(
                'INSERT INTO purchase_invoices
                    (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                     issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, exchange_rate_date,
                     exchange_rate_source, reverse_charge, language, vendor_snapshot, vat_classification_code,
                     total_without_vat, total_vat, total_with_vat, status, booked_at, paid_at, created_by)
                 VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, "cnb", ?, "cs", ?, ?, 0, 0, 0, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId, $v['id'], $vs, $vendorInvoiceNumber,
                $issueDate, $taxDate, $dueDate, $receivedAt,
                $v['currency_id'], $exchangeRate, $exchangeRate !== null ? $issueDate : null,
                $isRc ? 1 : 0,
                $vendorSnapshot,
                $isRc ? '24' : null, // dovoz služby (ř.12 + mirror ř.43); tuzemsko per položka
                $status, $bookedAt, $paidAt, $adminUserId,
            ]);
            $piId = (int) $pdo->lastInsertId();
            $track('purchase_invoice', $piId);

            // 1-3 položky z vendor poolu (popis + sazba + klasifikace k sobě patří)
            $itemCount = random_int(1, min(3, count($pool['items'])));
            $totalBase = 0; $totalVat = 0;
            for ($k = 0; $k < $itemCount; $k++) {
                [$description, $ratePct, $clsCode] = $pool['items'][($i + $k) % count($pool['items'])];
                $qty  = random_int(1, 5);
                $rate = $v['currency'] === 'CZK' ? random_int(500, 5000) : random_int(20, 200);
                $base = $qty * $rate;
                // RC: nominální sazba zůstává, daň 0 (samovyměří se až ve výkazech)
                $vatAmt = $isRc ? 0.0 : round($base * $ratePct / 100, 2);
                $totalBase += $base; $totalVat += $vatAmt;
                $pdo->prepare(
                    'INSERT INTO purchase_invoice_items
                        (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                         vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat,
                         vat_classification_code, order_index)
                     VALUES (?,?,?,"ks",?,?,?,?,?,?,?,?)'
                )->execute([
                    $piId, $description, $qty, $rate,
                    $ratePct >= 21 ? $stdVat : $lowVat, $ratePct,
                    $base, $vatAmt, $base + $vatAmt, $clsCode, $k,
                ]);
            }
            $totalWithVat = $totalBase + $totalVat;
            $pdo->prepare(
                'UPDATE purchase_invoices SET total_without_vat = ?, total_vat = ?, total_with_vat = ? WHERE id = ?'
            )->execute([$totalBase, $totalVat, $totalWithVat, $piId]);
            $purchaseCount++;
        }

        // ───── Pravidelné fakturace (2 šablony) ─────
        // Vystavení od 1. dne příštího měsíce (ať cron hned něco negeneruje a uživatel
        // si je v klidu prohlédne). Přes RecurringTemplateRepository (stejné defaulty jako UI).
        $firstNextMonth = $today->modify('first day of next month')->format('Y-m-d');
        $recurringTemplates = [
            [
                'client_idx' => 0, 'project_idx' => 0, 'currency_id' => $czkId,
                'name' => 'Měsíční hosting a údržba webu', 'frequency' => 'monthly',
                'language' => 'cs', 'rc' => 0, 'vat' => $stdVat,
                'items' => [
                    ['Webhosting + správa serveru', 2500.0],
                    ['Údržba webu (měsíční paušál)', 3500.0],
                ],
            ],
            [
                'client_idx' => 4, 'project_idx' => 7, 'currency_id' => $eurId,
                'name' => 'Quarterly support retainer', 'frequency' => 'quarterly',
                'language' => 'en', 'rc' => 1, 'vat' => $rcVat,
                'items' => [
                    ['Quarterly support & maintenance retainer', 1200.0],
                ],
            ],
        ];
        $recurringCount = 0;
        foreach ($recurringTemplates as $rt) {
            $tplId = $this->recurring->create([
                'supplier_id'     => $supplierId,
                'client_id'       => $clientIds[$rt['client_idx']],
                'project_id'      => $projectIds[$rt['project_idx']],
                'name'            => $rt['name'],
                'frequency'       => $rt['frequency'],
                'day_of_month'    => 1,
                'anchor_date'     => $firstNextMonth,
                'invoice_type'    => 'invoice',
                'currency_id'     => $rt['currency_id'],
                'language'        => $rt['language'],
                'reverse_charge'  => $rt['rc'],
                'payment_due_days' => 14,
                'auto_issue'      => 1,
                'auto_send_email' => 0,  // sample: negenerovat reálné e-maily
                'status'          => 'active',
            ], $adminUserId);
            $this->recurring->replaceItems($tplId, array_map(
                fn (array $it, int $k) => [
                    'description'            => $it[0],
                    'quantity'               => 1,
                    'unit'                   => 'ks',
                    'unit_price_without_vat' => $it[1],
                    'vat_rate_id'            => $rt['vat'],
                    'order_index'            => $k,
                ],
                $rt['items'],
                array_keys($rt['items']),
            ));
            $track('recurring_template', (int) $tplId);
            $recurringCount++;
        }

        // ───── Kniha jízd (1 firemní auto, 15 jízd, 6 tankování) ─────
        $logbook = $this->seedLogbook($pdo, $supplierId, $adminUserId, $today);
        $carId = (int) ($logbook['car_id'] ?? 0);
        $track('car', $carId);

        // Zapiš evidenci sample entit — řídí „Odebrat ukázková data" (přesné smazání)
        // i zobrazení tlačítka v UI (issue #162).
        if ($tracked !== []) {
            $ins = $pdo->prepare(
                'INSERT INTO sample_data_entries (supplier_id, entity_type, entity_id) VALUES (?, ?, ?)'
            );
            foreach ($tracked as [$type, $id]) {
                $ins->execute([$supplierId, $type, $id]);
            }
        }

        $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        // Sample data nejdou přes InvoiceActions, takže project/client revenue cache by zůstaly prázdné
        // → dashboard a top-clients koláč by hlásily nulu. Recompute všech vygenerovaných entit.
        // AŽ po commitu — StatsRecomputer si otevírá vlastní transakci.
        foreach ($projectIds as $pid) $this->stats->recomputeProject((int) $pid);
        foreach ($clientIds  as $cid) $this->stats->recomputeClient((int) $cid);
        foreach ($vendorIds  as $vid) $this->stats->recomputeClient((int) $vid);

        return [
            'clients'           => count($clientIds),
            'projects'          => count($projectIds),
            'invoices'          => 20,
            'credit_notes'      => 4,
            'vendors'           => count($vendorIds),
            'purchase_invoices' => $purchaseCount,
            'recurring'         => $recurringCount,
            'cars'              => $logbook['cars'],
            'trips'             => $logbook['trips'],
            'fuelings'          => $logbook['fuelings'],
        ];
    }

    /**
     * Kniha jízd — 1 firemní auto, 15 jízd a 6 tankování za poslední ~2 měsíce.
     * Evidenční vrstva (do DPH/statistik/dashboardů NEvstupuje), proto stačí přímé
     * inserty. Odometer řetězíme spojitě od počátečního stavu auta, tankování
     * umisťujeme do téhož rozsahu km, ať na sebe přehledy a souhrny sedí.
     *
     * @return array{cars:int, trips:int, fuelings:int, car_id:int}
     */
    private function seedLogbook(PDO $pdo, int $supplierId, int $adminUserId, \DateTimeImmutable $today): array
    {
        // ── Auto (výchozí, firemní) ──
        $odometerStart = 85000;
        $startDate = $today->modify('-2 months')->modify('first day of this month')->format('Y-m-d');
        $pdo->prepare(
            'INSERT INTO cars (supplier_id, registration, name, brand, model, vin, fuel_type,
                               odometer_start, odometer_start_date, is_default, is_archived, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, "diesel", ?, ?, 1, 0, NULL, ?)'
        )->execute([
            $supplierId, '5AB 1234', 'Octavia firemní', 'Škoda', 'Octavia Combi 2.0 TDI',
            'TMBJJ7NE5L0123456', $odometerStart, $startDate, $adminUserId,
        ]);
        $carId = (int) $pdo->lastInsertId();

        // Kategorie cest (business/private) určují daňovou relevanci jízdy. Migrace 0109 je
        // seeduje per supplier, ale při fresh installu běží PŘED vznikem supplieru (a setup je
        // neseeduje) → nový tenant je nemá. Idempotentně je proto zajistíme tady; ON DUPLICATE
        // + LAST_INSERT_ID(id) vrátí id existující řádky bez přepsání případné úpravy uživatele.
        $ensureCat = function (string $code, string $label, int $isPrivate, int $order) use ($pdo, $supplierId): int {
            $pdo->prepare(
                'INSERT INTO trip_categories (supplier_id, code, label, is_private, display_order)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            )->execute([$supplierId, $code, $label, $isPrivate, $order]);
            return (int) $pdo->lastInsertId();
        };
        $catBusiness = $ensureCat('business', 'Služební', 0, 10);
        $catPrivate  = $ensureCat('private', 'Soukromá', 1, 20);

        // ── 15 jízd (chronologicky; odometer se řetězí spojitě) ──
        // [dní zpět, čas od, čas do, odkud, kam, účel, km, soukromá?]
        $tripDefs = [
            [68, '08:15', '11:40', 'Praha', 'Brno',            'Schůzka s klientem BlueWave Digital', 205, false],
            [66, '15:00', '18:20', 'Brno', 'Praha',            'Návrat z jednání',                    205, false],
            [60, '09:00', '10:35', 'Praha', 'Plzeň',           'Instalace u zákazníka',                95, false],
            [60, '16:10', '17:45', 'Plzeň', 'Praha',           'Návrat z instalace',                   95, false],
            [54, '10:30', '11:25', 'Praha', 'Kolín',           'Konzultace IT infrastruktury',         65, false],
            [50, '08:40', '10:30', 'Praha', 'Hradec Králové',  'Školení zaměstnanců klienta',         115, false],
            [49, '14:00', '15:50', 'Hradec Králové', 'Praha',  'Návrat ze školení',                   115, false],
            [44, '11:15', '11:55', 'Praha', 'Benešov',         'Servis serveru u zákazníka',           40, false],
            [40, '07:50', '09:35', 'Praha', 'Liberec',         'Obchodní jednání — nová zakázka',     105, false],
            [39, '17:20', '19:05', 'Liberec', 'Praha',         'Návrat z jednání',                    105, false],
            [32, '09:30', '11:30', 'Praha', 'Karlovy Vary',    'Soukromá cesta',                      130, true],
            [31, '18:00', '20:00', 'Karlovy Vary', 'Praha',    'Soukromá cesta — návrat',             130, true],
            [24, '08:25', '10:25', 'Praha', 'Pardubice',       'Předání hotové zakázky',              125, false],
            [16, '07:30', '11:00', 'Praha', 'Olomouc',         'Konference — prezentace řešení',      280, false],
            [6,  '13:10', '13:45', 'Praha', 'Kladno',          'Nákup HW vybavení',                    30, false],
        ];

        $tripStmt = $pdo->prepare(
            'INSERT INTO trips (supplier_id, car_id, trip_date, time_start, time_end,
                                odometer_start, odometer_end, distance_km, category_id,
                                purpose, origin, destination, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)'
        );
        $odometer = $odometerStart;
        $tripsCount = 0;
        foreach ($tripDefs as [$daysBack, $timeStart, $timeEnd, $origin, $destination, $purpose, $km, $isPrivate]) {
            $odoStart = $odometer;
            $odoEnd   = $odometer + $km;
            $odometer = $odoEnd;
            $tripStmt->execute([
                $supplierId, $carId, $today->modify("-{$daysBack} days")->format('Y-m-d'),
                $timeStart, $timeEnd, $odoStart, $odoEnd, $km,
                $isPrivate ? $catPrivate : $catBusiness,
                $purpose, $origin, $destination, $adminUserId,
            ]);
            $tripsCount++;
        }

        // ── 6 tankování (nafta; odometer ve stejném rozsahu km jako jízdy) ──
        // [dní zpět, čas, stanice, litry, cena/l vč. DPH, odometer]
        $fuelDefs = [
            [67, '07:55', 'Praha-Zličín / Shell',       48.62, 35.90, 85200],
            [58, '08:30', 'Plzeň, Borská / OMV',        45.18, 36.40, 85560],
            [46, '12:05', 'Praha, Strašnice / EuroOil', 50.07, 35.50, 85930],
            [36, '07:40', 'Liberec / Benzina',          47.83, 37.10, 86250],
            [22, '09:15', 'Pardubice / MOL',            49.34, 36.80, 86560],
            [5,  '13:20', 'Praha-Zličín / Shell',       44.57, 38.20, 86790],
        ];

        $fuelStmt = $pdo->prepare(
            'INSERT INTO fuelings (supplier_id, car_id, fueled_date, fueled_time, fuel_type, quantity, unit,
                                   unit_price, amount_without_vat, amount_vat, amount_with_vat, currency,
                                   odometer, station, source, created_by)
             VALUES (?, ?, ?, ?, "Nafta", ?, "l", ?, ?, ?, ?, "CZK", ?, ?, "manual", ?)'
        );
        $fuelingsCount = 0;
        foreach ($fuelDefs as [$daysBack, $time, $station, $liters, $pricePerL, $odo]) {
            $withVat    = round($liters * $pricePerL, 2);
            $withoutVat = round($withVat / 1.21, 2);
            $vat        = round($withVat - $withoutVat, 2);
            $fuelStmt->execute([
                $supplierId, $carId, $today->modify("-{$daysBack} days")->format('Y-m-d'), $time,
                $liters, $pricePerL, $withoutVat, $vat, $withVat, $odo, $station, $adminUserId,
            ]);
            $fuelingsCount++;
        }

        return ['cars' => 1, 'trips' => $tripsCount, 'fuelings' => $fuelingsCount, 'car_id' => $carId];
    }

    private function nextPurchaseVarsymbol(PDO $pdo, int $supplierId, string $period): string
    {
        $pdo->prepare(
            'INSERT INTO purchase_invoice_counters (supplier_id, period, last_number) VALUES (?,?,1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1'
        )->execute([$supplierId, $period]);
        $stmt = $pdo->prepare('SELECT last_number FROM purchase_invoice_counters WHERE supplier_id=? AND period=?');
        $stmt->execute([$supplierId, $period]);
        $num = (int) $stmt->fetchColumn();
        return 'PF-' . $period . '-' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
    }

    private function nextVarsymbol(PDO $pdo, int $supplierId, string $type, string $period): string
    {
        $pdo->prepare(
            'INSERT INTO invoice_counters (supplier_id, invoice_type, period, last_number) VALUES (?,?,?,1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1'
        )->execute([$supplierId, $type, $period]);
        $stmt = $pdo->prepare('SELECT last_number FROM invoice_counters WHERE supplier_id=? AND invoice_type=? AND period=?');
        $stmt->execute([$supplierId, $type, $period]);
        $num = (int) $stmt->fetchColumn();
        $yy = substr($period, 2, 2);
        $mm = substr($period, 4, 2);
        $prefix = $type === 'proforma' ? '9' : ($type === 'credit_note' ? '7' : '');
        return $prefix . $yy . $mm . str_pad((string) $num, 3, '0', STR_PAD_LEFT);
    }
}
