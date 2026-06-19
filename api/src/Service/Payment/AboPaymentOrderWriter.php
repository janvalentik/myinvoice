<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

use MyInvoice\Service\Export\ExportFilename;

/**
 * Generátor tuzemského příkazu k úhradě ve formátu **ABO / KPC** (Česká spořitelna
 * a kompatibilní banky). Ověřeno proti `private/KPC/ABO_format.pdf` + reálnému
 * `private/KPC/abo-payment-96.kpc`.
 *
 * Skládáme **hromadný příkaz** (jeden účet plátce v hlavičce skupiny, položky bez
 * debetního účtu, jedno datum splatnosti pro celou dávku):
 *
 *   UHL1<ddmmrr><20× název klienta><10× číslo klienta>000999000000000000   (hlavička souboru)
 *   1 1501 <sssppp> <směr.kód banky plátce>                                 (hlavička účet. souboru)
 *   2 <prefix-číslo plátce> <celk.částka v haléřích> <ddmmrr splatnost>      (hlavička skupiny)
 *   <prefix-číslo příjemce> <částka v haléřích> <VS> <KS8> <SS10> AV:<zpráva> (položka)
 *   ... další položky ...
 *   3 +                                                                     (konec skupiny)
 *   5 +                                                                     (konec souboru)
 *
 * Klíčové konvence (z ověřeného `.kpc`):
 *   - částka v HALÉŘÍCH (×100), zleva doplněná nulami: skupina 14 míst, položka 12 míst,
 *   - číslo účtu = `předčíslí(6)-číslo(10)` doplněné nulami zleva (BEZ kódu banky — ten je v KS),
 *   - **KS pole = 8 číslic:** levé 4 = směrový kód banky příjemce, pravé 4 = konstantní symbol
 *     (např. `01000308` = banka 0100 + KS 0308),
 *   - specifický symbol 10 míst (nuly), variabilní symbol jen číslice (bez paddingu),
 *   - řádky ukončené CRLF, výstup čisté ASCII (diakritika transliterována, nepovolené znaky pryč →
 *     soubor lze nahrát v UTF-8, viz ABO spec „pro soubory bez diakritiky UTF-8").
 *
 * ABO je tuzemský CZK platební styk → příjemce MUSÍ mít český účet (číslo + kód banky).
 * Položky bez něj (jen IBAN / zahraniční) sem nepatří — volající je má odfiltrovat;
 * writer je defenzivně odmítne výjimkou.
 */
final class AboPaymentOrderWriter
{
    private const CRLF = "\r\n";

    /** Druh dat pro úhrady (1502 = inkasa, to neděláme). */
    private const DATA_KIND = '1501';

    /**
     * Sestaví ABO/KPC text příkazu k úhradě.
     *
     * @param array{
     *     client_name?: string,
     *     client_number?: ?string,
     *     file_number?: ?string,
     *     payer_account_number: string,
     *     payer_bank_code: string,
     *     payment_date: string|\DateTimeInterface,
     *     items: list<array{account_number?:?string, bank_code?:?string, amount:int|float,
     *                        variable_symbol?:?string, constant_symbol?:?string,
     *                        specific_symbol?:?string, message?:?string}>
     * } $order
     *
     * @throws \InvalidArgumentException při prázdné dávce nebo příjemci bez českého účtu
     */
    public function build(array $order): string
    {
        $items = $order['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException('Platební příkaz neobsahuje žádnou položku.');
        }

        $date = $this->normalizeDate($order['payment_date']);
        $ddmmrr = $date->format('dmy');

        [$payerPrefix, $payerNumber] = $this->splitAccount((string) $order['payer_account_number']);
        if ($payerNumber === '') {
            throw new \InvalidArgumentException('Účet plátce nemá platné číslo účtu.');
        }
        $payerBank = $this->digits((string) $order['payer_bank_code']);

        // Číslo klienta (UHL1) = povinná část čísla účtu plátce (bez předčíslí), 10 míst.
        // Override z nastavení dodavatele má přednost (banka ho někdy přiděluje zvlášť).
        $clientNumber = $this->digits((string) ($order['client_number'] ?? ''));
        if ($clientNumber === '') {
            $clientNumber = $payerNumber;
        }
        $clientNumber = $this->padLeft($clientNumber, 10);

        $clientName = $this->asciiField((string) ($order['client_name'] ?? ''), 20);
        $fileNumber = $this->padLeft($this->digits((string) ($order['file_number'] ?? '1')) ?: '1', 6);

        // Sestav položky a zároveň spočítej celkovou částku skupiny v haléřích.
        $itemLines = [];
        $totalHaler = 0;
        foreach ($items as $i => $item) {
            [$line, $haler] = $this->buildItemLine($item, $i);
            $itemLines[] = $line;
            $totalHaler += $haler;
        }

        $lines = [];
        // Hlavička účetního souboru (UHL1): datum + název(20) + číslo klienta(10) +
        // interval 000–999 + kódy 000000 000000 (oktalové, banka nevyžaduje).
        $lines[] = 'UHL1' . $ddmmrr . $clientName . $clientNumber . '000' . '999' . '000000' . '000000';
        // Hlavička účetního souboru: typ 1, druh dat, číslo souboru, směrový kód banky plátce.
        $lines[] = '1 ' . self::DATA_KIND . ' ' . $fileNumber . ' ' . $this->padLeft($payerBank, 4);
        // Hlavička skupiny: typ 2, účet příkazce, celková částka (14 míst, haléře), splatnost.
        $lines[] = '2 ' . $payerPrefix . '-' . $payerNumber
            . ' ' . $this->padLeft((string) $totalHaler, 14)
            . ' ' . $ddmmrr;
        foreach ($itemLines as $l) {
            $lines[] = $l;
        }
        $lines[] = '3 +'; // konec skupiny
        $lines[] = '5 +'; // konec účetního souboru

        return implode(self::CRLF, $lines) . self::CRLF;
    }

    /**
     * Jeden řádek položky + její částka v haléřích.
     *
     * @param array<string,mixed> $item
     * @return array{0:string,1:int}
     */
    private function buildItemLine(array $item, int $index): array
    {
        [$prefix, $number] = $this->splitAccount((string) ($item['account_number'] ?? ''));
        $bank = $this->digits((string) ($item['bank_code'] ?? ''));
        if ($number === '' || $bank === '') {
            throw new \InvalidArgumentException(
                "Položka #" . ($index + 1) . " nemá český účet (číslo + kód banky) — do ABO ji nelze zařadit."
            );
        }

        $haler = $this->toHaler($item['amount'] ?? 0);
        if ($haler <= 0) {
            throw new \InvalidArgumentException('Položka #' . ($index + 1) . ' má nekladnou částku.');
        }

        $vs = $this->digits((string) ($item['variable_symbol'] ?? ''));
        $vs = $vs === '' ? '0' : substr($vs, 0, 10);

        // KS pole (8 míst) = směrový kód banky příjemce (4) + konstantní symbol (4).
        $ks = $this->digits((string) ($item['constant_symbol'] ?? ''));
        $ksField = $this->padLeft($bank, 4) . $this->padLeft($ks, 4);

        // Specifický symbol (10 míst, nuly pokud chybí).
        $ss = $this->digits((string) ($item['specific_symbol'] ?? ''));
        $ssField = $this->padLeft($ss, 10);

        // Zpráva pro příjemce (max 35 vč. prefixu „AV:"), čisté ASCII.
        $msg = 'AV:' . $this->asciiMessage((string) ($item['message'] ?? $vs));
        $msg = substr($msg, 0, 35);

        $line = $prefix . '-' . $number
            . ' ' . $this->padLeft((string) $haler, 12)
            . ' ' . $vs
            . ' ' . $ksField
            . ' ' . $ssField
            . ' ' . $msg;

        return [$line, $haler];
    }

    /**
     * Rozdělí číslo účtu na předčíslí (6 míst) a číslo (10 míst), obojí zleva nulami.
     * Akceptuje „prefix-číslo", „prefix-číslo/kód" i samotné „číslo".
     *
     * @return array{0:string,1:string} [prefix(6), number(10)] — number '' když nerozpoznáno
     */
    private function splitAccount(string $account): array
    {
        $account = (string) preg_replace('/\s+/', '', $account);
        // Odřízni „/kódBanky" — kód banky příjemce jde do KS pole, ne do účtu.
        $slash = strpos($account, '/');
        if ($slash !== false) {
            $account = substr($account, 0, $slash);
        }
        if (str_contains($account, '-')) {
            [$p, $n] = explode('-', $account, 2);
        } else {
            $p = '';
            $n = $account;
        }
        $p = $this->digits($p);
        $n = $this->digits($n);
        if ($n === '') {
            return ['000000', ''];
        }
        return [$this->padLeft($p, 6), $this->padLeft($n, 10)];
    }

    /** Částku (Kč) převede na celé haléře (×100, korektní zaokrouhlení). */
    private function toHaler(int|float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function digits(string $s): string
    {
        return (string) preg_replace('/\D+/', '', $s);
    }

    private function padLeft(string $s, int $len): string
    {
        return str_pad($s, $len, '0', STR_PAD_LEFT);
    }

    /**
     * Alfanumerické pole pevné délky (název klienta): transliterace diakritiky na ASCII,
     * nepovolené znaky → mezera, zarovnání zprava mezerami na danou délku.
     */
    private function asciiField(string $s, int $len): string
    {
        $s = ExportFilename::transliterate($s);
        $s = (string) preg_replace('/[^A-Za-z0-9 ]/', ' ', $s);
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        $s = substr($s, 0, $len);
        return str_pad($s, $len, ' ', STR_PAD_RIGHT);
    }

    /**
     * Zpráva pro příjemce → čisté ASCII bez nepovolených znaků platebního styku
     * (ABO doporučuje vynechat `-#,.;/&_*=+"?()[]` apod.). Necháme alfanumeriku + mezeru.
     */
    private function asciiMessage(string $s): string
    {
        $s = ExportFilename::transliterate($s);
        $s = (string) preg_replace('/[^A-Za-z0-9 ]/', '', $s);
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    private function normalizeDate(string|\DateTimeInterface $date): \DateTimeImmutable
    {
        if ($date instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($date);
        }
        return new \DateTimeImmutable($date);
    }
}
