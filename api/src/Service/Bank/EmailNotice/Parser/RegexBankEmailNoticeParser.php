<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class RegexBankEmailNoticeParser implements BankEmailNoticeParserInterface
{
    private EmailNoticeTextNormalizer $normalizer;

    public function __construct(?EmailNoticeTextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new EmailNoticeTextNormalizer();
    }

    public function supports(BankEmailNoticeMessage $message, array $provider): bool
    {
        if (!$this->senderAllowed($message->sender, (string) ($provider['sender_whitelist'] ?? ''))) {
            return false;
        }
        $subjectPattern = trim((string) ($provider['subject_pattern'] ?? ''));
        if ($subjectPattern !== '' && !preg_match('~' . $subjectPattern . '~iu', $message->subject)) {
            return false;
        }
        $bodyPattern = trim((string) ($provider['body_pattern'] ?? ''));
        if ($bodyPattern !== '' && !preg_match('~' . $bodyPattern . '~iu', $this->normalizer->normalize($message->text))) {
            return false;
        }
        return true;
    }

    public function parse(BankEmailNoticeMessage $message, array $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizer->normalize($message->text);
        $patterns = $provider['field_patterns'] ?? [];
        if (!is_array($patterns)) {
            throw new \RuntimeException('Provider nemá validní field_patterns.');
        }

        $data = [];
        foreach ($patterns as $field => $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                continue;
            }
            if (preg_match('~' . $pattern . '~u', $text, $m) !== 1) {
                continue;
            }
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $data[$key] = trim((string) $value);
                }
            }
            if (!isset($data[$field]) && isset($m[1])) {
                $data[$field] = trim((string) $m[1]);
            }
        }

        // Některé banky (např. Česká spořitelna) datum platby v těle avíza neuvádějí —
        // jako fallback použij datum doručení e-mailu, ať povinné pole nechybí.
        if (trim((string) ($data['posted_at'] ?? '')) === '' && $message->date instanceof \DateTimeImmutable) {
            $data['posted_at'] = $message->date->format('d.m.Y H:i');
        }

        foreach (['variable_symbol', 'amount', 'currency', 'posted_at', 'recipient_account'] as $required) {
            if (trim((string) ($data[$required] ?? '')) === '') {
                throw new \RuntimeException("Parser nenašel povinné pole {$required}.");
            }
        }

        [$cpAccount, $cpBank] = $this->splitAccount((string) ($data['counterparty_account'] ?? ''));
        $postedAt = $this->parseDate((string) $data['posted_at']);

        return new ParsedBankEmailNotice(
            variableSymbol: preg_replace('/\D+/', '', (string) $data['variable_symbol']) ?? (string) $data['variable_symbol'],
            amount: $this->applyDirection($this->parseAmount((string) $data['amount']), (string) ($data['direction'] ?? '')),
            currency: $this->normalizeCurrency((string) $data['currency']),
            postedAt: $postedAt,
            recipientAccount: $this->normalizeAccount((string) $data['recipient_account']),
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            counterpartyName: $this->cleanNullable((string) ($data['counterparty_name'] ?? '')),
            constantSymbol: $this->cleanNullable((string) ($data['constant_symbol'] ?? '')),
            message: $this->cleanNullable((string) ($data['message'] ?? '')),
            bankRef: $this->cleanNullable((string) ($data['bank_ref'] ?? '')),
        );
    }

    private function senderAllowed(string $sender, string $whitelist): bool
    {
        $whitelist = trim($whitelist);
        if ($whitelist === '') {
            return true;
        }
        $sender = strtolower($sender);
        foreach (preg_split('/[\s,;]+/', strtolower($whitelist)) ?: [] as $allowed) {
            $allowed = trim($allowed);
            if ($allowed === '') {
                continue;
            }
            if ($sender === $allowed || str_contains($sender, '<' . $allowed . '>')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sjednotí měnu na ISO kód. Banky často píší symbol „Kč" / „€" místo „CZK" / „EUR".
     */
    private function normalizeCurrency(string $value): string
    {
        $v = trim($value);
        $upper = mb_strtoupper($v, 'UTF-8');
        $map = [
            'KČ' => 'CZK', 'KC' => 'CZK', 'CZK' => 'CZK',
            '€' => 'EUR', 'EUR' => 'EUR',
            '$' => 'USD', 'USD' => 'USD',
            '£' => 'GBP', 'GBP' => 'GBP',
            'ZŁ' => 'PLN', 'ZL' => 'PLN', 'PLN' => 'PLN',
        ];
        if (isset($map[$upper])) {
            return $map[$upper];
        }
        if (isset($map[$v])) {
            return $map[$v];
        }
        // Ponech jen písmena (odstraní tečky/mezery); 3-písmenný ISO kód vrať jak je.
        $letters = preg_replace('/[^A-ZÁ-Ž]/u', '', $upper) ?? $upper;
        if (isset($map[$letters])) {
            return $map[$letters];
        }
        return $letters !== '' ? mb_substr($letters, 0, 3, 'UTF-8') : $upper;
    }

    /**
     * Česká spořitelna nerozlišuje příjem/výdej znaménkem, ale řádkem
     * „Směr platby: příchozí/odchozí". Odchozí platbu ulož se záporným znaménkem
     * (konzistentní s GPC), ať se nepáruje proti pohledávkám.
     */
    private function applyDirection(float $amount, string $direction): float
    {
        $direction = mb_strtolower(trim($direction), 'UTF-8');
        if ($direction === '') {
            return $amount;
        }
        if (preg_match('/odchoz|výdej|vydej|debet|odepsán|odepsan|outgoing/u', $direction) === 1) {
            return -abs($amount);
        }
        return abs($amount);
    }

    private function parseAmount(string $value): float
    {
        $v = str_replace(["\xc2\xa0", ' ', '+'], '', trim($value));
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);
        }
        return (float) $v;
    }

    private function parseDate(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        foreach (['d. m. Y H:i', 'd.m.Y H:i', 'd. m. Y', 'd.m.Y', \DateTimeInterface::ATOM, \DateTimeInterface::RFC2822] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            throw new \RuntimeException('Parser nenašel validní datum platby.');
        }
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function splitAccount(string $value): array
    {
        $value = $this->normalizeAccount($value);
        if ($value === '') {
            return [null, null];
        }
        if (preg_match('/^(?<account>[0-9\-]+)\/(?<bank>[0-9]{4})$/', $value, $m) === 1) {
            return [$m['account'], $m['bank']];
        }
        return [$value, null];
    }

    private function normalizeAccount(string $value): string
    {
        $value = trim($value);
        if (preg_match('/[0-9\-]+\/[0-9]{4}/', $value, $m) === 1) {
            return $m[0];
        }
        return $value;
    }

    private function cleanNullable(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }
}
