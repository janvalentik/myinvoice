<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\Fuel;

/**
 * Univerzální poslední záchrana (supports() = vždy true).
 *
 *  • Má-li faktura strukturované palivové položky (ISDOC / ruční), rozpadne je 1:1
 *    (datum = issue_date, množství/ceny z položky).
 *  • Jinak vytvoří jeden souhrnný záznam: datum vystavení + text + celková částka.
 *    (Dle zadání: „pokud nepůjde analyzovat přesně kdy, dej datum vystavení + text + částku".)
 */
final class SummaryFuelParser implements FuelStatementParser
{
    public function name(): string
    {
        return 'summary';
    }

    public function supports(array $invoice): bool
    {
        return true;
    }

    public function parse(array $invoice, ?string $pdfBytes): ?array
    {
        // Datum plnění = DUZP (tax_date) → datum vystavení; bez transakčního data je DUZP nejblíž realitě.
        $issueDate = (string) ($invoice['tax_date'] ?? '');
        if ($issueDate === '') $issueDate = (string) ($invoice['issue_date'] ?? '');
        if ($issueDate === '') return null;
        $currency = (string) ($invoice['currency'] ?? 'CZK');
        $items = is_array($invoice['items'] ?? null) ? $invoice['items'] : [];

        $rows = [];
        foreach ($items as $it) {
            $desc = (string) ($it['description'] ?? '');
            if ($desc === '' || !FuelKeywords::isFuel($desc)) continue;
            $qty = isset($it['quantity']) ? (float) $it['quantity'] : null;
            $base = isset($it['total_without_vat']) ? (float) $it['total_without_vat'] : null;
            $vat = isset($it['total_vat']) ? (float) $it['total_vat'] : null;
            $total = isset($it['total_with_vat']) ? (float) $it['total_with_vat'] : 0.0;
            $rows[] = [
                'fueled_date'        => $issueDate,
                'fueled_time'        => null,
                'fuel_type'          => mb_substr($desc, 0, 60),
                'quantity'           => $qty,
                'unit'               => (string) ($it['unit'] ?? 'l'),
                'unit_price'         => isset($it['unit_price_without_vat']) ? (float) $it['unit_price_without_vat'] : null,
                'amount_without_vat' => $base,
                'amount_vat'         => $vat,
                'amount_with_vat'    => $total,
                'currency'           => $currency,
                'station'            => null,
                'receipt_number'     => null,
                'is_fuel'            => true,
                'raw_text'           => mb_substr($desc, 0, 500),
                'source_item_id'     => isset($it['id']) ? (int) $it['id'] : null,
            ];
        }

        if ($rows !== []) {
            return ['transactions' => $rows, 'status' => 'parsed'];
        }

        // Žádné palivové položky → jeden souhrnný záznam za celou fakturu.
        $total = (float) ($invoice['total_with_vat'] ?? ($invoice['totals']['with_vat'] ?? 0));
        if ($total <= 0) return null;
        $label = 'Tankování';
        foreach ($items as $it) {
            $d = trim((string) ($it['description'] ?? ''));
            if ($d !== '') { $label = mb_substr($d, 0, 60); break; }
        }
        return [
            'transactions' => [[
                'fueled_date'        => $issueDate,
                'fueled_time'        => null,
                'fuel_type'          => $label,
                'quantity'           => null,
                'unit'               => 'l',
                'unit_price'         => null,
                'amount_without_vat' => isset($invoice['total_without_vat']) ? (float) $invoice['total_without_vat'] : null,
                'amount_vat'         => isset($invoice['total_vat']) ? (float) $invoice['total_vat'] : null,
                'amount_with_vat'    => $total,
                'currency'           => $currency,
                'station'            => null,
                'receipt_number'     => null,
                'is_fuel'            => true,
                'raw_text'           => 'Faktura ' . (string) ($invoice['vendor_invoice_number'] ?? ''),
            ]],
            'status' => 'summary',
        ];
    }
}
