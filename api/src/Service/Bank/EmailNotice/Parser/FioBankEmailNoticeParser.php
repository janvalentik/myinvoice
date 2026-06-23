<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

/**
 * Fio banka — avízo „Fio banka - prijem/vydaj na konte" (automat@fio.cz).
 *
 * Tělo je řádkové `Pole: hodnota`:
 *   Příjem na kontě: 2901618111      (nebo „Výdaj na kontě:" — směr nese label)
 *   Částka: 3 898,00
 *   VS: 26006439
 *   Zpráva příjemci: text            (u výdaje „US: text")
 *   Aktuální zůstatek: 168 459,32
 *   Protiúčet: 1234567890/0800
 *   SS:
 *   KS:
 *
 * Avízo neobsahuje datum (bere se z Date hlavičky e-mailu) ani měnu
 * (default CZK, případný kód za částkou se respektuje). Účet „na kontě"
 * je bez kódu banky — doplní se /2010 (účet vedený u Fio).
 */
final class FioBankEmailNoticeParser extends AbstractBankEmailNoticeParser
{
    public function key(): string
    {
        return 'fio';
    }

    protected function parserLabel(): string
    {
        return 'Fio banka';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'system:' . $this->key(),
            code: $this->key(),
            name: 'Fio banka - příjem/výdaj na kontě',
            parserType: $this->key(),
            enabled: true,
            senderWhitelist: 'automat@fio.cz',
            subjectPattern: 'Fio\\s+banka\\s+-\\s+(?:prijem|vydaj|příjem|výdaj)\\s+na\\s+kont[ěe]',
            bodyPattern: '(?:Příjem|Výdaj)\\s+na\\s+kontě',
            fieldPatterns: [],
            normalizerConfig: [],
            system: true,
        );
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!$this->senderMatchesDomain($message, 'fio.cz')) {
            return false;
        }

        $subject = $this->compact(mb_strtolower($message->subject, 'UTF-8'));
        if (
            !str_contains($subject, 'fio banka')
            || preg_match('/(?:prijem|vydaj|příjem|výdaj)\s+na\s+kont[ěe]/u', $subject) !== 1
        ) {
            return false;
        }

        $text = $this->compact(mb_strtolower($this->normalizeText($message->text), 'UTF-8'));
        return preg_match('/(?:příjem|výdaj|prijem|vydaj)\s+na\s+kont[ěe]\s*:/u', $text) === 1
            && (str_contains($text, 'částka') || str_contains($text, 'castka'));
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizeText($message->text);

        $header = $this->match(
            $text,
            '/(?:^|\R)\s*(?<direction>Příjem|Prijem|Výdaj|Vydaj)\s+na\s+kont[ěe]\s*:\s*(?<account>[0-9\-]+(?:\/[0-9]{4})?)/iu',
        );
        if ($header === null) {
            throw new \RuntimeException('Fio banka parser nenašel cílový účet.');
        }
        $amountCurrency = $this->match(
            $text,
            '/(?:^|\R)\s*Částka\s*:\s*(?<amount>[+\-]?[0-9][0-9 .]*,[0-9]{2})(?:\s*(?<currency>[A-Za-z]{3}|Kč))?/u',
        );
        if ($amountCurrency === null) {
            throw new \RuntimeException('Fio banka parser nenašel částku.');
        }
        if (!$message->date instanceof \DateTimeImmutable) {
            throw new \RuntimeException('Fio banka parser nenašel datum e-mailu.');
        }

        $variableSymbol = $this->optional($text, '/(?:^|\R)\s*VS\s*:\s*(?<value>[0-9]+)/u');
        $constantSymbol = $this->optional($text, '/(?:^|\R)\s*KS\s*:\s*(?<value>[0-9]+)/u');
        $note = $this->optional($text, '/(?:^|\R)\s*(?:Zpráva\s+příjemci|US)\s*:\s*(?<value>[^\r\n]+)/u');
        $counterparty = $this->optional($text, '/(?:^|\R)\s*Protiúčet\s*:\s*(?<value>[0-9\-]+(?:\/[0-9]{4})?)/u');

        [$recipientAccount, $recipientBank] = $this->splitAccount((string) $header['account']);
        [$cpAccount, $cpBank] = $this->splitAccount((string) $counterparty);

        $currency = trim((string) ($amountCurrency['currency'] ?? ''));

        return new ParsedBankEmailNotice(
            variableSymbol: $this->normalizeSymbol((string) $variableSymbol),
            amount: $this->applyDirection($this->parseAmount((string) $amountCurrency['amount']), (string) $header['direction']),
            currency: $currency !== '' ? $this->normalizeCurrency($currency) : 'CZK',
            postedAt: $message->date->format('Y-m-d'),
            recipientAccount: $recipientAccount . '/' . ($recipientBank ?? '2010'),
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            constantSymbol: $constantSymbol,
            message: $note,
        );
    }

}
