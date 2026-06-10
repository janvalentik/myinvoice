<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

/**
 * Rozpozná z volného textu (z AI extrakce nebo ISDOC) český bankovní účet
 * ve formátu [prefix-]číslo/kód_banky nebo IBAN a vrátí strukturovaná pole
 * pro uložení do purchase_invoices.payment_* a pro QrPaymentGenerator.
 *
 * Bez závislostí — čistá funkce, snadno testovatelná.
 */
final class BankAccountParser
{
    /**
     * Rozparsuje volný řetězec na účet/IBAN.
     *
     * @return array{account_number?:string,bank_code?:string,iban?:string}
     *         prázdné pole pokud nic použitelného nerozpoznáno
     */
    public function parse(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        // IBAN — písmena+číslice, ve vstupu mohou být mezery (CZ65 0800 0000 …).
        $ibanCandidate = strtoupper((string) preg_replace('/\s+/', '', $raw));
        if (preg_match('/\b([A-Z]{2}\d{2}[A-Z0-9]{11,30})\b/', $ibanCandidate, $m) === 1
            && $this->isValidIban($m[1])) {
            return ['iban' => $m[1]];
        }

        // Český účet [prefix-]číslo/kód — kolem oddělovačů mohou být mezery.
        $compact = (string) preg_replace('/\s*([\/-])\s*/', '$1', $raw);
        if (preg_match('#(?:(\d{1,6})-)?(\d{2,10})/(\d{4})#', $compact, $m) === 1) {
            $prefix  = $m[1] ?? '';
            $number  = $m[2];
            $bank    = $m[3];
            $account = $prefix !== '' ? $prefix . '-' . $number : $number;
            return ['account_number' => $account, 'bank_code' => $bank];
        }

        return [];
    }

    /**
     * Sestaví pole pro QrPaymentGenerator::generate() z uložených payment_* hodnot.
     *
     * @return array{account_number:string,bank_code:string,iban:string,bic:string}
     */
    public function bankSnapshot(
        ?string $accountNumber,
        ?string $bankCode,
        ?string $iban,
        ?string $bic,
    ): array {
        return [
            'account_number' => (string) ($accountNumber ?? ''),
            'bank_code'      => (string) ($bankCode ?? ''),
            'iban'           => (string) ($iban ?? ''),
            'bic'            => (string) ($bic ?? ''),
        ];
    }

    /**
     * Máme dost údajů na sestavení QR? CZK chce účet+kód nebo IBAN, SEPA chce IBAN.
     */
    public function hasAccount(?string $accountNumber, ?string $bankCode, ?string $iban): bool
    {
        $hasCz = ($accountNumber ?? '') !== '' && ($bankCode ?? '') !== '';
        return $hasCz || ($iban ?? '') !== '';
    }

    /**
     * IBAN mod-97 kontrola (ISO 13616) — odfiltruje náhodné alfanumerické řetězce.
     */
    private function isValidIban(string $iban): bool
    {
        $len = strlen($iban);
        if ($len < 15 || $len > 34) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $remainder = 0;
        foreach (str_split($numeric) as $d) {
            $remainder = ($remainder * 10 + (int) $d) % 97;
        }
        return $remainder === 1;
    }
}
