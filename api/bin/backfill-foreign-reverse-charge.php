<?php

declare(strict_types=1);

/**
 * Oprava chybně naimportovaných ZAHRANIČNÍCH reverse-charge přijatých faktur.
 *
 * PROBLÉM
 * -------
 * Služby od zahraničních osob neusazených v tuzemsku (Anthropic, GitHub, Foxit,
 * Apple, Google …) jsou pro českého plátce předmětem DPH formou samovyměření
 * (§ 9 odst. 1 + § 24 + § 108 ZDPH): příjemce přizná daň na výstupu a SOUČASNĚ
 * uplatní nárok na odpočet (ř. 43). To, že dodavatel není plátce DPH a není
 * z EU, na povinnosti nic nemění — naopak je to důvod reverse charge.
 *
 * Import (AiPdfExtractor + PurchaseInvoiceRepository::defaultClassificationCode)
 * tyto doklady (BEZ vyčíslené DPH) zařazoval špatně:
 *   (1) mimo-EU + 0 % → kód 25 ("dovoz ZBOŽÍ ze 3. země", ř. 7) místo služby (ř. 12);
 *   (2) dodavatel-neplátce → vat_deduction='none' → celý doklad VYPADL z přiznání
 *       (chybí na ř. 12 i ř. 43 i v obratu), ač nárok na odpočet je.
 *
 * POZOR — doklad, kde zahraniční dodavatel REÁLNĚ NAÚČTOVAL DPH (Apple, Google …),
 * NENÍ reverse charge: jde nejčastěji o českou DPH přes OSS jako B2C (chybí tvé DIČ,
 * dodavatel tě vzal jako spotřebitele). Taková CZ DPH NENÍ odpočitatelná a samovyměřovat
 * se nesmí (dvojí zdanění) — celá částka je náklad, doklad patří „bez nároku na odpočet"
 * a do DPH přiznání nevstupuje. Skript ho proto NEopravuje, jen vypíše k ruční kontrole.
 *
 * CO SKRIPT DĚLÁ
 * --------------
 * Najde přijaté faktury od zahraničních dodavatelů:
 *   - dodavatel mimo CZ (countries.iso2 <> 'CZ'),
 *   - dodavatel NENÍ registrovaný k české DPH (dic prázdné nebo ne 'CZ…') — tím se
 *     vyloučí zahraniční firmy s CZ registrací, které účtují českou DPH (Amazon EU
 *     S.à r.l. s CZ DIČ apod. → ty patří na ř. 40, ne do RC),
 *   - není to zálohová výzva (document_kind <> 'advance') ani stornovaný.
 *
 * Doklad BEZ vyčíslené DPH (total_vat = 0) = skutečný reverse charge → opraví:
 *   - reverse_charge = 1, vat_deduction = 'full' (nárok na odpočet u RC náleží příjemci),
 *   - všem položkám správný klasifikační kód podle povahy plnění:
 *       služba (default) → EU 24e (ř. 5) / 3. země 24 (ř. 12)
 *       zboží (--goods)  → EU 23 (ř. 3) / 3. země 25 (ř. 7)
 * Doklad S vyčíslenou DPH (OSS B2C / cizí daň) → NEopravuje, jen WARNING k ruční kontrole.
 *
 * Povaha plnění se z dat spolehlivě nepozná → DEFAULT je SLUŽBA (drtivá většina jsou
 * digitální předplatná). Dodavatele dodávající ZBOŽÍ vyjmenuj v --goods.
 *
 * Částky se NEMĚNÍ: samovyměřená daň i odpočet se počítají živě ve VatLedgerService
 * z (základ × sazba). Skript mění jen kódy/příznaky/deduction. Daňový dopad reverse
 * charge je nulový (výstup +X / odpočet −X) → vlastní daňová povinnost se nemění,
 * mění se jen ZAŘAZENÍ na správné řádky a to, že doklad do přiznání vůbec vstoupí.
 *
 * ⚠️ Už PODANÁ období: skript chytá i historické doklady. Než spustíš --apply na
 *    produkci, omez rozsah přes --from/--to tak, ať nepřepíšeš zařazení v obdobích,
 *    na která je už podané přiznání (po dohodě s účetní, příp. dodatečné přiznání).
 *    Idempotentní — opakované spuštění už nic nezmění.
 *
 * Použití:
 *   php api/bin/backfill-foreign-reverse-charge.php                          # dry-run, vše
 *   php api/bin/backfill-foreign-reverse-charge.php --from=2026-04-01        # jen od data DUZP
 *   php api/bin/backfill-foreign-reverse-charge.php --from=2026-04-01 --apply
 *   php api/bin/backfill-foreign-reverse-charge.php --supplier=1             # jen jeden tenant
 *   php api/bin/backfill-foreign-reverse-charge.php --goods=123,456          # tito dodavatelé = zboží
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun     = !in_array('--apply', $argv, true);
$supplierId = null;
$from       = null;
$to         = null;
$goodsIds   = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--supplier=')) $supplierId = (int) substr($a, 11);
    if (str_starts_with($a, '--from='))     $from = substr($a, 7);
    if (str_starts_with($a, '--to='))       $to   = substr($a, 5);
    if (str_starts_with($a, '--goods='))    $goodsIds = array_filter(array_map('intval', explode(',', substr($a, 8))));
}

$app = \MyInvoice\Bootstrap::buildApp();
$pdo = $app->getContainer()->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

// Doklady = jistá zahraniční RC: mimo CZ, bez CZ DPH registrace, ne záloha/storno.
$params = [];
$sql = "
    SELECT pi.id, pi.supplier_id, pi.varsymbol, pi.vendor_id, pi.reverse_charge, pi.vat_deduction,
           COALESCE(pi.tax_date, pi.issue_date) AS taxd,
           pi.total_without_vat, pi.total_vat, pi.total_with_vat,
           c.company_name AS vendor, COALESCE(co.is_eu,0) AS is_eu, COALESCE(co.iso2,'') AS iso2
      FROM purchase_invoices pi
      JOIN clients c     ON c.id  = pi.vendor_id
 LEFT JOIN countries co  ON co.id = c.country_id
     WHERE COALESCE(co.iso2,'CZ') <> 'CZ'
       AND (c.dic IS NULL OR c.dic = '' OR c.dic NOT LIKE 'CZ%')
       AND COALESCE(pi.document_kind,'') <> 'advance'
       AND pi.status <> 'cancelled'
";
if ($supplierId !== null) { $sql .= " AND pi.supplier_id = ?"; $params[] = $supplierId; }
if ($from !== null)       { $sql .= " AND COALESCE(pi.tax_date, pi.issue_date) >= ?"; $params[] = $from; }
if ($to !== null)         { $sql .= " AND COALESCE(pi.tax_date, pi.issue_date) <= ?"; $params[] = $to; }
$sql .= " ORDER BY pi.supplier_id, taxd, pi.id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$docs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (!$docs) {
    echo "Žádné odpovídající zahraniční reverse-charge doklady.\n";
    exit(0);
}

$itemStmt    = $pdo->prepare("SELECT id, vat_classification_code FROM purchase_invoice_items WHERE purchase_invoice_id = ?");
$updItemCode = $pdo->prepare("UPDATE purchase_invoice_items SET vat_classification_code = ? WHERE id = ?");
$updDoc      = $pdo->prepare("UPDATE purchase_invoices SET reverse_charge = 1, vat_deduction = 'full', vat_classification_code = ? WHERE id = ?");

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Zahraniční reverse-charge doklady: " . count($docs) . "\n";
echo str_repeat('-', 118) . "\n";

$changed = 0; $itemChanges = 0; $warned = 0;
foreach ($docs as $d) {
    $vat = (float) $d['total_vat'];

    // Doklad s VYČÍSLENOU DPH od zahraničního dodavatele bez CZ registrace NENÍ reverse
    // charge: dodavatel daň naúčtoval sám — typicky českou DPH přes OSS jako B2C (chybí
    // tvé DIČ, vzal tě jako spotřebitele), případně svou národní daň. CZ DPH z OSS NENÍ
    // odpočitatelná a samovyměřovat ji nesmíš (dvojí zdanění). Takový doklad patří „bez
    // nároku na odpočet" (celá částka = náklad, mimo DPH přiznání) — necháme ho BEZE
    // ZMĚNY a jen upozorníme na ruční kontrolu.
    if (abs($vat) >= 0.005) {
        printf("  ⚠ t%-2d pi#%-5d %-11s %-16s %s  naúčtovaná DPH %.2f → NENÍ reverse charge (OSS B2C / cizí daň), neodpočitatelné — zkontroluj ručně (bez nároku na odpočet), NEopravuji.\n",
            $d['supplier_id'], $d['id'], (string) $d['taxd'], substr((string) $d['vendor'], 0, 16), $d['iso2'], $vat);
        $warned++;
        continue;
    }

    // vat == 0 → skutečný reverse charge (samovyměření + zrcadlový odpočet, net nula).
    $isGoods = in_array((int) $d['vendor_id'], $goodsIds, true);
    // Cílový kód: služba → EU 24e / 3. země 24; zboží → EU 23 / 3. země 25.
    $target = $isGoods
        ? ((int) $d['is_eu'] === 1 ? '23' : '25')
        : ((int) $d['is_eu'] === 1 ? '24e' : '24');

    $itemStmt->execute([$d['id']]);
    $itemFixes = []; // [id, oldCode]
    foreach ($itemStmt->fetchAll(\PDO::FETCH_ASSOC) as $it) {
        if ((string) $it['vat_classification_code'] !== $target) {
            $itemFixes[] = [(int) $it['id'], (string) $it['vat_classification_code']];
        }
    }
    $dedFix = $d['vat_deduction'] !== 'full';
    $rcFix  = (int) $d['reverse_charge'] !== 1;
    if (!$itemFixes && !$dedFix && !$rcFix) {
        continue; // idempotent
    }

    $flags = [];
    if ($rcFix)  $flags[] = 'rc→1';
    if ($dedFix) $flags[] = $d['vat_deduction'] . '→full';
    $arrows = array_map(fn ($f) => ($f[1] === '' ? '∅' : $f[1]) . "→{$target}", $itemFixes);

    printf("  t%-2d pi#%-5d %-11s %-16s %s [%s] %s | %s\n",
        $d['supplier_id'], $d['id'], (string) $d['taxd'], substr((string) $d['vendor'], 0, 16),
        $d['iso2'], $isGoods ? 'ZBOŽÍ' : 'služba',
        $flags ? '(' . implode(', ', $flags) . ')' : '', $arrows ? implode(',', $arrows) : 'kódy OK');

    if (!$dryRun) {
        $pdo->beginTransaction();
        try {
            foreach ($itemFixes as $f) { $updItemCode->execute([$target, $f[0]]); }
            $updDoc->execute([$target, $d['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    $changed++;
    $itemChanges += count($itemFixes);
}

echo str_repeat('-', 118) . "\n";
echo "Reverse charge opraveno: {$changed} dokladů (položek: {$itemChanges}).\n";
if ($warned)   echo "K ruční kontrole (zahr. dodavatel naúčtoval DPH → OSS B2C / neodpočitatelné): {$warned}.\n";
if ($goodsIds) echo "Bráno jako ZBOŽÍ: dodavatelé #" . implode(',', $goodsIds) . "\n";
if ($dryRun) {
    echo "\nDRY-RUN — nic nezapsáno. Pro zápis přidej --apply (zvaž --from kvůli už podaným obdobím).\n";
} else {
    echo "\nHotovo. DPH výkazy se počítají živě — žádný recompute není potřeba.\n";
}
