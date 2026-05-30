<?php

declare(strict_types=1);

/**
 * Backfill plátcovství DPH u VŠECH klientů (zákazníci i dodavatelé) + oprava nároku na
 * odpočet u přijatých faktur od dodavatelů-neplátců.
 *
 * Use case: dodavatel je NEPLÁTCE DPH (zjištěno z ARES dle IČO / VIES dle DIČ), ale jeho
 * historické přijaté faktury byly zaúčtovány s `vat_deduction='full'` (typicky AI import
 * před opravou). Od neplátce nelze DPH odpočítat → tyto faktury patří do `vat_deduction='none'`
 * (VatLedgerService je pak vyloučí z DPH přiznání ř.40 i z KH sekce B).
 *
 * Co skript dělá:
 *   1. Pro KAŽDÉHO klienta (zákazník i dodavatel): zjistí plátcovství z ARES (CZ IČO) /
 *      VIES (zahr. DIČ) a uloží `clients.is_vat_payer`.
 *   2. Navíc pro DODAVATELE (is_vendor=1), který je NEPLÁTCE: jeho přijatým fakturám
 *      (mimo cancelled) s `vat_deduction <> 'none'` NEBO s nějakou DPH sazbou > 0:
 *        a) nastaví `vat_deduction='none'` (vyloučení z odpočtu),
 *        b) nastaví DPH sazby položek na 0 % a ZACHOVÁ zaúčtovanou částku — celé „s DPH"
 *           se stane základem (DPH = 0, total beze změny), protože od neplátce žádné DPH není,
 *        c) přepíše interní prefix čísla (PF→NN dle uplatnění).
 *
 * Použití:
 *   php api/bin/backfill-vendor-vat-payer.php           # dry-run (jen náhled, nic nezapisuje)
 *   php api/bin/backfill-vendor-vat-payer.php --apply    # provede změny
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$vatPayer = $container->get(\MyInvoice\Service\Ares\VendorVatPayerResolver::class);
$piRepo = $container->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);
$calc = $container->get(\MyInvoice\Service\Invoice\PurchaseInvoiceCalculator::class);

// 0% nereverzní sazba pro vynulování položek neplátce.
$zeroRateId = (int) ($pdo->query(
    "SELECT id FROM vat_rates WHERE rate_percent = 0 AND is_reverse_charge = 0 ORDER BY id LIMIT 1"
)->fetchColumn() ?: 0);

$clients = $pdo->query(
    "SELECT id, supplier_id, company_name, ic, dic, is_vat_payer, is_customer, is_vendor
       FROM clients
      WHERE archived_at IS NULL AND (ic IS NOT NULL OR dic IS NOT NULL)
      ORDER BY supplier_id, company_name"
)->fetchAll(\PDO::FETCH_ASSOC);

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Procházím " . count($clients) . " klientů s IČO/DIČ (ARES/VIES lookup, cache 24 h)…\n\n";

// Faktury k opravě: buď mají odpočet (vat_deduction <> 'none'), nebo ještě nesou DPH sazbu > 0.
$invStmt = $pdo->prepare(
    "SELECT pi.id, pi.supplier_id, pi.vendor_invoice_number, pi.status, pi.vat_deduction, pi.total_with_vat
       FROM purchase_invoices pi
      WHERE pi.vendor_id = ? AND pi.status <> 'cancelled'
        AND (pi.vat_deduction <> 'none'
             OR EXISTS (SELECT 1 FROM purchase_invoice_items pii
                         WHERE pii.purchase_invoice_id = pi.id AND pii.vat_rate_snapshot > 0))"
);
$setStmt = $pdo->prepare("UPDATE purchase_invoices SET vat_deduction = 'none' WHERE id = ?");
// Položky neplátce: cena bez DPH := cena s DPH (gross), sazba 0 %. Recompute pak dá
// základ = celé „s DPH", DPH = 0, total beze změny (od neplátce žádné DPH není).
$zeroItemsStmt = $pdo->prepare(
    "UPDATE purchase_invoice_items
        SET unit_price_without_vat = IF(quantity <> 0, ROUND(total_with_vat / quantity, 2), total_with_vat),
            vat_rate_id = ?, vat_rate_snapshot = 0
      WHERE purchase_invoice_id = ?"
);

$nonPayers = 0;
$payers = 0;
$unknown = 0;
$flippedFlags = 0;
$fixedInvoices = 0;

foreach ($clients as $v) {
    $id       = (int) $v['id'];
    $ic       = isset($v['ic'])  ? (string) $v['ic']  : null;
    $dic      = isset($v['dic']) ? (string) $v['dic'] : null;
    $isVendor = (int) ($v['is_vendor'] ?? 0) === 1;
    $role     = $isVendor ? ((int) ($v['is_customer'] ?? 0) === 1 ? 'K+D' : 'dodav.') : 'zákazn.';

    $res = $dryRun ? $vatPayer->resolve($ic, $dic) : $vatPayer->resolveAndPersist($id, $ic, $dic);
    $isVatPayer = $res['is_vat_payer'];

    if ($isVatPayer === null) {
        $unknown++;
        continue; // ARES/VIES nerozhodly → příznak neměníme
    }

    $flagChanged = (int) ($v['is_vat_payer'] ?? 1) !== (int) $isVatPayer;
    if ($flagChanged) $flippedFlags++;

    if ($isVatPayer === true) {
        $payers++;
        if ($flagChanged) {
            echo sprintf("  PLÁTCE   tenant=%-2d %-7s %-40s  (příznak →plátce, zdroj=%s)\n",
                $v['supplier_id'], $role, mb_substr((string) $v['company_name'], 0, 40), $res['source']);
        }
        continue;
    }

    // === NEPLÁTCE ===
    $nonPayers++;

    // Opravu faktur (vat_deduction='none' + sazby 0 %) řešíme JEN u dodavatelů.
    if (!$isVendor) {
        echo sprintf("  NEPLÁTCE tenant=%-2d %-7s %-40s  IČO=%-10s zdroj=%-7s  (jen příznak)\n",
            $v['supplier_id'], $role, mb_substr((string) $v['company_name'], 0, 40), $ic ?? '—', $res['source']);
        continue;
    }

    $invStmt->execute([$id]);
    $invoices = $invStmt->fetchAll(\PDO::FETCH_ASSOC);

    echo sprintf("  NEPLÁTCE tenant=%-2d %-7s %-40s  IČO=%-10s zdroj=%-7s  faktur k opravě: %d\n",
        $v['supplier_id'], $role, mb_substr((string) $v['company_name'], 0, 40), $ic ?? '—', $res['source'], count($invoices));

    foreach ($invoices as $inv) {
        $invId = (int) $inv['id'];
        echo sprintf("      pi#%-6d %-9s  č.=%-16s  vat_deduction:%s→none, sazby→0%%, total %.2f beze změny\n",
            $invId, $inv['status'], $inv['vendor_invoice_number'] ?: '(none)', $inv['vat_deduction'], (float) $inv['total_with_vat']);
        $fixedInvoices++;
        if (!$dryRun) {
            $setStmt->execute([$invId]);                       // vat_deduction = 'none'
            $zeroItemsStmt->execute([$zeroRateId, $invId]);    // cena bez DPH := s DPH, sazba 0 %
            $calc->recompute($invId);                          // základ = s DPH, DPH = 0, total beze změny
            // Přepiš prefix interního čísla dle nového uplatnění (PF→NN). No-op u draftu / ručních čísel.
            $piRepo->reprefixVarsymbol($invId, (int) $inv['supplier_id']);
        }
    }
}

echo "\nSouhrn:\n";
echo "  plátci:                {$payers}\n";
echo "  neplátci:              {$nonPayers}\n";
echo "  nezjištěno (skip):     {$unknown}\n";
echo "  příznak is_vat_payer změněn: {$flippedFlags}\n";
echo "  faktur → vat_deduction='none': {$fixedInvoices}\n";

if ($dryRun) {
    echo "\nSpusť znovu s --apply pro skutečný zápis.\n";
} else {
    echo "\nHotovo. Po backfill spusť 'Přepočítat' v /crm dashboardu, aby se DPH přiznání aktualizovala.\n";
}
