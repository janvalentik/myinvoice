<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\Fuel;

use MyInvoice\Service\Import\AnthropicClient;

/**
 * Univerzální AI fallback — vytěží transakce přes Claude, když interní parser nevyšel.
 * supports() = tenant má nastavený BYOK Anthropic klíč. Funguje pro Axigon i jiné
 * karetní společnosti bez vlastního parseru.
 */
final class AiFuelStatementParser implements FuelStatementParser
{
    public function __construct(private readonly AnthropicClient $anthropic) {}

    public function name(): string
    {
        return 'axigon_ai';
    }

    public function supports(array $invoice): bool
    {
        // Jen pro karetní výpisy s detailem (zatím Axigon) a jen když má tenant BYOK klíč —
        // ať AI nejede na běžné fakturní položky každé benzínky (zbytečný náklad).
        if (!AxigonStatementParser::isAxigonVendor($invoice)) return false;
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        return $supplierId > 0 && $this->anthropic->getCredentials($supplierId) !== null;
    }

    public function parse(array $invoice, ?string $pdfBytes): ?array
    {
        if ($pdfBytes === null || !str_starts_with($pdfBytes, '%PDF')) return null;
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        if ($supplierId === 0) return null;

        $res = $this->anthropic->extractFuelTransactions($supplierId, $pdfBytes);
        if (empty($res['ok']) || empty($res['transactions'])) return null;

        $currency = (string) ($invoice['currency'] ?? 'CZK');
        $rows = [];
        foreach ((array) $res['transactions'] as $t) {
            if (!is_array($t)) continue;
            $row = $this->mapRow($t, $currency);
            if ($row !== null) $rows[] = $row;
        }
        if ($rows === []) return null;

        return ['transactions' => $rows, 'status' => 'parsed'];
    }

    private function mapRow(array $t, string $currency): ?array
    {
        $date = $this->normalizeDate((string) ($t['fueled_date'] ?? ''));
        if ($date === null) return null;
        $total = isset($t['amount_with_vat']) ? (float) $t['amount_with_vat'] : 0.0;
        $fuelType = isset($t['fuel_type']) ? trim((string) $t['fuel_type']) : null;
        $isFuel = array_key_exists('is_fuel', $t)
            ? (bool) $t['is_fuel']
            : ($fuelType !== null && FuelKeywords::isFuel($fuelType));

        return [
            'fueled_date'        => $date,
            'fueled_time'        => $this->normalizeTime($t['fueled_time'] ?? null),
            'fuel_type'          => $fuelType !== '' ? $fuelType : null,
            'quantity'           => isset($t['quantity']) && $t['quantity'] !== null ? (float) $t['quantity'] : null,
            'unit'               => FuelKeywords::canonicalUnit($t['unit'] ?? null, (string) ($fuelType ?? '')),
            'unit_price'         => isset($t['unit_price']) && $t['unit_price'] !== null ? (float) $t['unit_price'] : null,
            'amount_without_vat' => isset($t['amount_without_vat']) && $t['amount_without_vat'] !== null ? (float) $t['amount_without_vat'] : null,
            'amount_vat'         => isset($t['amount_vat']) && $t['amount_vat'] !== null ? (float) $t['amount_vat'] : null,
            'amount_with_vat'    => $total,
            'currency'           => $currency,
            'station'            => isset($t['station']) && $t['station'] !== null ? mb_substr((string) $t['station'], 0, 150) : null,
            'receipt_number'     => isset($t['receipt_number']) && $t['receipt_number'] !== null ? mb_substr((string) $t['receipt_number'], 0, 40) : null,
            'is_fuel'            => $isFuel,
            'raw_text'           => null,
        ];
    }

    private function normalizeDate(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $s, $m)) {
            $y = (int) $m[3]; if ($y < 100) $y += 2000;
            return sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[1]);
        }
        return null;
    }

    private function normalizeTime(mixed $v): ?string
    {
        if ($v === null) return null;
        if (preg_match('/(\d{1,2}):(\d{2})/', (string) $v, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        return null;
    }
}
