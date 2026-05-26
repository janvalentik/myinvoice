<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

final class InvoiceValidation
{
    /**
     * @param array<int, float>|null $vatRates
     * @return array<string, string[]>
     */
    public static function invoice(array $data, ?array $vatRates = null): array
    {
        $err = [];

        $type = (string) ($data['invoice_type'] ?? 'invoice');
        if (!in_array($type, ['invoice', 'proforma', 'credit_note', 'cancellation'], true)) {
            $err['invoice_type'][] = 'Neplatný typ dokladu';
        }

        if (array_key_exists('payment_method', $data) && $data['payment_method'] !== null && $data['payment_method'] !== '') {
            $pm = (string) $data['payment_method'];
            if (!in_array($pm, ['bank_transfer', 'card', 'cash', 'other'], true)) {
                $err['payment_method'][] = 'Neplatný způsob úhrady';
            }
        }

        if (empty($data['client_id']) || !is_numeric($data['client_id'])) {
            $err['client_id'][] = 'Klient je povinný';
        }

        if (isset($data['currency_id']) && (int) $data['currency_id'] <= 0) {
            $err['currency_id'][] = 'Neplatné currency_id';
        }

        if (!empty($data['issue_date']) && !self::isValidDate((string) $data['issue_date'])) {
            $err['issue_date'][] = 'Neplatné datum vystavení';
        }
        if (!empty($data['due_date']) && !self::isValidDate((string) $data['due_date'])) {
            $err['due_date'][] = 'Neplatné datum splatnosti';
        }
        if ($type !== 'proforma' && !empty($data['tax_date']) && !self::isValidDate((string) $data['tax_date'])) {
            $err['tax_date'][] = 'Neplatné DUZP';
        }

        $items = $data['items'] ?? [];
        if (!is_array($items)) {
            $err['items'][] = 'items musí být pole';
        } else {
            foreach (array_values($items) as $i => $item) {
                if (!is_array($item)) {
                    $err["items.{$i}"][] = 'Neplatná položka';
                    continue;
                }
                $err = array_merge($err, InvoiceAmountPolicy::validateItem($item, $i));
            }
        }

        $advance = (float) ($data['advance_paid_amount'] ?? 0);
        if ($advance < 0) {
            $err['advance_paid_amount'][] = 'Záloha nesmí být záporná';
        }

        if (array_key_exists('discount_percent', $data) && $data['discount_percent'] !== null && $data['discount_percent'] !== '') {
            if (!is_numeric($data['discount_percent'])) {
                $err['discount_percent'][] = 'Sleva musí být číslo';
            } else {
                $d = (float) $data['discount_percent'];
                if ($d < 0 || $d > 100) {
                    $err['discount_percent'][] = 'Sleva musí být mezi 0 a 100 %';
                }
            }
        }

        if ($vatRates !== null) {
            $amountError = InvoiceAmountPolicy::validatePositiveAmountToPay($data, $vatRates);
            if ($amountError !== null) {
                $err['amount_to_pay'][] = $amountError;
            }
        }

        // Volitelný manuální varsymbol u draftu (override automatického číslování).
        // Prázdný / chybějící = generuje se při issue. Max 20 znaků (DB limit).
        if (array_key_exists('varsymbol', $data) && $data['varsymbol'] !== null && $data['varsymbol'] !== '') {
            $vs = (string) $data['varsymbol'];
            if (strlen($vs) > 20) {
                $err['varsymbol'][] = 'Číslo faktury má max 20 znaků';
            }
            if (preg_match('/[\x00-\x1f\x7f]/', $vs)) {
                $err['varsymbol'][] = 'Číslo faktury obsahuje neplatné znaky';
            }
        }

        return $err;
    }

    private static function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
