<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

/**
 * CSV export platebního příkazu (UTF-8 BOM, `;` oddělovač) — pro ruční zadání do
 * banky nebo archiv. Sloupce kryjí vše potřebné k platbě i ověření účtu příjemce.
 *
 * Bezpečnost: OWASP CSV-injection guard (prefix `'` u buněk začínajících `= + - @ TAB CR`),
 * shodně s `Action\Invoice\ExportCsvAction`.
 */
final class PaymentOrderCsvWriter
{
    /**
     * @param array<string,mixed> $order kanonický snapshot příkazu (viz PaymentOrderService::orderView)
     */
    public function build(array $order): string
    {
        $fp = fopen('php://temp', 'w+');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel)

        fputcsv($fp, [
            'Příjemce', 'Účet', 'Kód banky', 'IBAN', 'BIC',
            'Částka', 'Měna', 'VS', 'KS', 'SS', 'Splatnost', 'Zpráva', 'Ověření účtu',
        ], ';', '"', '\\');

        $safe = static function ($v): string {
            $s = (string) ($v ?? '');
            return preg_replace('/^([=+\-@\t\r])/u', "'\\1", $s) ?? $s;
        };

        $dueDate = $this->czDate((string) ($order['payment_date'] ?? ''));
        foreach ((array) ($order['items'] ?? []) as $it) {
            fputcsv($fp, [
                $safe($it['payee_name'] ?? ''),
                $safe($it['account_number'] ?? ''),
                $safe($it['bank_code'] ?? ''),
                $safe($it['iban'] ?? ''),
                $safe($it['bic'] ?? ''),
                number_format((float) ($it['amount'] ?? 0), 2, '.', ''),
                $safe($it['currency'] ?? $order['currency'] ?? ''),
                $safe($it['variable_symbol'] ?? ''),
                $safe($it['constant_symbol'] ?? ''),
                $safe($it['specific_symbol'] ?? ''),
                $dueDate,
                $safe($it['message'] ?? ''),
                $this->verificationLabel((string) ($it['account_verified'] ?? 'na')),
            ], ';', '"', '\\');
        }

        rewind($fp);
        $csv = (string) stream_get_contents($fp);
        fclose($fp);
        return $csv;
    }

    private function verificationLabel(string $v): string
    {
        return match ($v) {
            'verified'   => 'Zveřejněný účet',
            'not_listed' => 'Nezveřejněný účet',
            'unreliable' => 'Nespolehlivý plátce',
            default      => '',
        };
    }

    private function czDate(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($raw))->format('d.m.Y');
        } catch (\Throwable) {
            return $raw;
        }
    }
}
