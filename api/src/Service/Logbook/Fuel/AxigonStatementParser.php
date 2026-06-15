<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\Fuel;

use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Interní parser detailních výpisů Axigon (str. 2 PDF, „Podrobné vyúčtování").
 *
 * Dva formáty (oba ověřené na reálných dokladech):
 *   • novější — každá buňka řádku je na samostatném řádku textu, transakce začíná
 *     kódem země (CZ/SK) na vlastním řádku; částky mapujeme od konce.
 *   • starší — celý řádek na jedné textové řádce; základ a celkem jsou čistě odděleny
 *     mezerou („100,00 121,00"), zbytek (DPH/sazba/JC/množství) je zřetězený.
 *
 * Self-check: Σ „celkem s DPH" parsovaných řádků ≈ total_with_vat faktury (tolerance).
 * Když nesedí, vrací null → registry zkusí AI fallback.
 */
final class AxigonStatementParser implements FuelStatementParser
{
    private const COUNTRY_CODES = ['CZ','SK','DE','AT','PL','HU','SI','HR','IT','FR','NL','BE','LU','RO','BG','GR','ES','PT','LT','LV','EE','FI','SE','DK','IE','CH'];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function name(): string
    {
        return 'axigon';
    }

    public function supports(array $invoice): bool
    {
        return self::isAxigonVendor($invoice);
    }

    /**
     * Vendor je Axigon (karetní výpis s detailem tankování)? Sdílené i pro AI fallback,
     * aby AI nešlo na běžné fakturní položky každé benzínky (zbytečný náklad).
     *
     * @param array<string,mixed> $invoice
     */
    public static function isAxigonVendor(array $invoice): bool
    {
        $ic = preg_replace('/\D+/', '', (string) ($invoice['vendor_ic'] ?? ''));
        if ($ic === '64949320') return true;
        $name = FuelKeywords::normalize((string) ($invoice['vendor_company_name'] ?? ($invoice['vendor_name'] ?? '')));
        return str_contains($name, 'axigon');
    }

    public function parse(array $invoice, ?string $pdfBytes): ?array
    {
        if ($pdfBytes === null || !str_starts_with($pdfBytes, '%PDF')) return null;

        try {
            $text = $this->detailText($pdfBytes);
        } catch (\Throwable $e) {
            $this->logger->warning('Axigon PDF parse failed', ['error' => $e->getMessage()]);
            return null;
        }
        if ($text === '') return null;

        $rows = $this->parseRowsFromText($text);
        if ($rows === []) return null;

        // Self-check proti celkové částce faktury.
        $invoiceTotal = (float) ($invoice['total_with_vat'] ?? ($invoice['totals']['with_vat'] ?? 0));
        $sum = 0.0;
        foreach ($rows as $r) $sum += (float) $r['amount_with_vat'];
        $tolerance = max(1.0, abs($invoiceTotal) * 0.01);
        if ($invoiceTotal > 0 && abs($sum - $invoiceTotal) > $tolerance) {
            $this->logger->info('Axigon self-check mismatch', ['parsed_sum' => $sum, 'invoice_total' => $invoiceTotal]);
            return null;
        }

        $currency = (string) ($invoice['currency'] ?? 'CZK');
        foreach ($rows as &$r) {
            $r['currency'] = $currency;
        }
        unset($r);

        return ['transactions' => $rows, 'status' => 'parsed'];
    }

    /**
     * Testovací seam — vytěží řádky transakcí z textu detailu (bez self-checku / PDF).
     *
     * @return list<array<string,mixed>>
     */
    public function parseRowsFromText(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        return $this->parseRows($lines);
    }

    /** Text stránky s detailním vyúčtováním (jinak poslední stránka). */
    private function detailText(string $pdfBytes): string
    {
        $doc = (new PdfParser())->parseContent($pdfBytes);
        $pages = $doc->getPages();
        if ($pages === []) return '';
        $detail = '';
        foreach ($pages as $pg) {
            $t = $pg->getText();
            if (stripos($t, 'Podrobné') !== false || stripos($t, 'vyúčtování') !== false || stripos($t, 'Číslo karty') !== false) {
                $detail .= "\n" . $t;
            }
        }
        if (trim($detail) === '') {
            $detail = $pages[count($pages) - 1]->getText();
        }
        return $detail;
    }

    /**
     * @param list<string> $lines
     * @return list<array<string,mixed>>
     */
    private function parseRows(array $lines): array
    {
        $rows = [];
        $slice = null;
        foreach ($lines as $raw) {
            $t = trim((string) preg_replace('/\x{00A0}/u', ' ', $raw));
            if ($t === '') continue;

            // Starší formát — celý řádek začíná kódem země + datem.
            if (preg_match('/^([A-Z]{2})\s+(\d{1,2}\.\d{1,2}\.\d{2,4})\b/u', $t, $m) && in_array($m[1], self::COUNTRY_CODES, true)) {
                if ($slice !== null) { $this->flushSlice($slice, $rows); $slice = null; }
                $row = $this->parseInlineRow($t);
                if ($row !== null) $rows[] = $row;
                continue;
            }
            // Novější formát — kód země na samostatném řádku zahajuje transakci.
            if (in_array($t, self::COUNTRY_CODES, true)) {
                if ($slice !== null) $this->flushSlice($slice, $rows);
                $slice = [];
                continue;
            }
            if ($this->isStopMarker($t)) {
                if ($slice !== null) { $this->flushSlice($slice, $rows); $slice = null; }
                continue;
            }
            if ($slice !== null) $slice[] = $t;
        }
        if ($slice !== null) $this->flushSlice($slice, $rows);
        return $rows;
    }

    private function isStopMarker(string $t): bool
    {
        $n = FuelKeywords::normalize($t);
        foreach (['celkem', 'strana', 'struktura tankovani', 'rekapitulace', 'nazev site', 'tankovani v siti'] as $kw) {
            if (str_starts_with($n, FuelKeywords::normalize($kw))) return true;
        }
        return false;
    }

    /**
     * Novější formát — slice řádků jedné transakce (od kódu země po další/stop).
     *
     * @param list<string> $slice
     * @param list<array<string,mixed>> $rows
     */
    private function flushSlice(array $slice, array &$rows): void
    {
        if ($slice === []) return;

        $datetime = null; $moneys = []; $decimals = []; $vatRate = null; $texts = []; $receipt = null;
        foreach ($slice as $ln) {
            if ($datetime === null && preg_match('/(\d{1,2}\.\d{1,2}\.\d{2,4})\s+(\d{1,2}:\d{2}(?::\d{2})?)/', $ln, $m)) {
                $datetime = [$m[1], $m[2]];
                continue;
            }
            if (preg_match('/^(\d[\d \x{00A0}]*,\d{2})\s*Kč$/u', $ln, $m)) { $moneys[] = $this->num($m[1]); continue; }
            if (preg_match('/^(\d{1,2}(?:,\d{1,2})?)\s*%$/', $ln, $m)) { $vatRate = $this->num($m[1]); continue; }
            if (preg_match('/^\d{3,}$/', $ln) && $receipt === null) { $receipt = $ln; continue; }
            if (preg_match('/^\d+,\d{1,3}$/', $ln)) { $decimals[] = $this->num($ln); continue; }
            if (preg_match('/\p{L}/u', $ln)) { $texts[] = $ln; }
        }
        if ($datetime === null || $moneys === []) return;

        $n = count($moneys);
        $total  = $moneys[$n - 1];
        $vat    = $n >= 2 ? $moneys[$n - 2] : null;
        $base   = $n >= 3 ? $moneys[$n - 3] : null;
        $price  = $n >= 4 ? $moneys[$n - 4] : null; // jednotková cena po slevě
        $fuelType = $texts[0] ?? null;
        $station  = count($texts) > 1 ? implode(' / ', array_slice($texts, 1)) : null;
        $quantity = $decimals[0] ?? null;

        $rows[] = $this->makeRow($datetime, $fuelType, $quantity, $price, $base, $vat, $total, $station, $receipt, implode(' ', $slice));
    }

    /** Starší formát — celý řádek na jedné textové řádce. */
    private function parseInlineRow(string $line): ?array
    {
        if (!preg_match('/^[A-Z]{2}\s+(\d{1,2}\.\d{1,2}\.\d{2,4})\s+(\d{1,2}:\d{2}(?::\d{2})?)/u', $line, $dm)) {
            return null;
        }
        $datetime = [$dm[1], $dm[2]];

        // Základ a celkem jsou čistě odděleny mezerou („100,00 121,00" / „1 000,00 1 210,00").
        $base = null; $total = null;
        if (preg_match('/(\d{1,3}(?:[ \x{00A0}]\d{3})*,\d{2})\s+(\d{1,3}(?:[ \x{00A0}]\d{3})*,\d{2})/u', $line, $mm)) {
            $base  = $this->num($mm[1]);
            $total = $this->num($mm[2]);
        }
        if ($total === null) return null;

        // Název produktu = koncová abecední fráze („Diesel plus").
        $fuelType = null;
        if (preg_match('/([\p{L}][\p{L} ]*[\p{L}])\s*$/u', $line, $pm)) {
            $fuelType = trim($pm[1]);
        }
        // Množství/jednotkovou cenu ve starším formátu NElze spolehlivě oddělit (hodnoty
        // jsou zřetězené: „32,9817,44") → necháváme null (poctivě), total reconciluje.
        $quantity = null;
        // Místo/síť — alfanumerické tokeny (síť + město), bez data/produktu.
        $station = null;
        if (preg_match_all('/\b([\p{Lu}][\p{L}]{2,})\b/u', $line, $sm)) {
            $cands = array_values(array_filter($sm[1], fn ($w) => $w !== $fuelType && mb_strlen($w) > 2 && !preg_match('/^[A-Z]{2}$/', $w)));
            if ($cands !== []) $station = implode(', ', array_slice(array_unique($cands), 0, 2));
        }

        return $this->makeRow($datetime, $fuelType, $quantity, null, $base, null, $total, $station, null, $line);
    }

    /**
     * @param array{0:string,1:string} $datetime  [date dd.mm.yyyy, time HH:MM[:SS]]
     */
    private function makeRow(array $datetime, ?string $fuelType, ?float $quantity, ?float $unitPrice, ?float $base, ?float $vat, float $total, ?string $station, ?string $receipt, string $rawText): array
    {
        [$date, $time] = $this->normalizeDateTime($datetime[0], $datetime[1]);
        $isFuel = $fuelType !== null ? FuelKeywords::isFuel($fuelType) : false;
        return [
            'fueled_date'        => $date,
            'fueled_time'        => $time,
            'fuel_type'          => $fuelType,
            'quantity'           => $quantity,
            'unit'               => FuelKeywords::canonicalUnit(null, (string) $fuelType),
            'unit_price'         => $unitPrice,
            'amount_without_vat' => $base,
            'amount_vat'         => $vat,
            'amount_with_vat'    => $total,
            'currency'           => 'CZK',
            'station'            => $station !== null ? mb_substr($station, 0, 150) : null,
            'receipt_number'     => $receipt,
            'is_fuel'            => $isFuel,
            'raw_text'           => mb_substr($rawText, 0, 500),
        ];
    }

    /** "1 155,94" → 1155.94 */
    private function num(string $s): float
    {
        $s = str_replace(["\u{00A0}", ' '], '', $s);
        $s = str_replace(',', '.', $s);
        return (float) $s;
    }

    /** @return array{0:string,1:?string} [Y-m-d, H:i|null] */
    private function normalizeDateTime(string $date, string $time): array
    {
        $parts = explode('.', $date);
        $d = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);
        $y = (int) ($parts[2] ?? 0);
        if ($y < 100) $y += 2000;
        $iso = sprintf('%04d-%02d-%02d', $y, $m, $d);
        $t = null;
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $tm)) {
            $t = sprintf('%02d:%02d', (int) $tm[1], (int) $tm[2]);
        }
        return [$iso, $t];
    }
}
