<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class CsobBankEmailNoticeParser extends AbstractBankEmailNoticeParser
{
    public function key(): string
    {
        return 'csob';
    }

    protected function parserLabel(): string
    {
        return 'ČSOB';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'system:' . $this->key(),
            code: $this->key(),
            name: 'ČSOB - Moje info Avízo',
            parserType: $this->key(),
            enabled: true,
            senderWhitelist: 'noreply@csob.cz',
            subjectPattern: 'Moje\\s+info\\s+-\\s+Avízo|Moje\\s+info\\s+-\\s+Avizo',
            bodyPattern: 'Parametry\\s+platby',
            fieldPatterns: [],
            normalizerConfig: [],
            system: true,
        );
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!$this->senderMatchesDomain($message, 'csob.cz')) {
            return false;
        }

        $subject = $this->compact(mb_strtolower($message->subject, 'UTF-8'));
        if (
            !str_contains($subject, 'moje info')
            || (!str_contains($subject, 'avízo') && !str_contains($subject, 'avizo'))
        ) {
            return false;
        }

        $text = $this->compact(mb_strtolower($this->normalizeText($message->text), 'UTF-8'));
        return str_contains($text, 'parametry platby')
            && (str_contains($text, 'vaše čsob') || str_contains($text, 'vase csob') || str_contains($text, 'čsob'))
            && (str_contains($text, 'částka') || str_contains($text, 'castka'));
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizeText($message->text);

        $recipientAccount = $this->required(
            $text,
            '/(?:^|\R)\s*Účet\s*\R\s*(?<value>[0-9\-]+\/[0-9]{4})/u',
            'cílový účet',
        );
        $counterpartyAccount = $this->optional(
            $text,
            '/(?:^|\R)\s*Účet\s+protistrany\s*\R\s*(?<value>[0-9\-]+\/[0-9]{4})/u',
        );
        $counterpartyName = $this->optional(
            $text,
            '/(?:^|\R)\s*Název\s+protistrany\s*\R\s*(?<value>[^\r\n]+)/u',
        );
        $postedAt = $this->required(
            $text,
            '/(?:^|\R)\s*Datum\s+účtování\s*\R\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4})/u',
            'datum účtování',
        );
        $amountCurrency = $this->match(
            $text,
            '/(?:^|\R)\s*Částka\s*\R\s*(?<amount>[+\-]?[0-9 ]+,[0-9]{2})\s*(?<currency>[A-Z]{3})/u',
        );
        if ($amountCurrency === null) {
            throw new \RuntimeException('ČSOB parser nenašel částku a měnu.');
        }
        $variableSymbol = $this->optional(
            $text,
            '/(?:^|\R)\s*Variabilní\s+symbol\s*\R\s*(?<value>[0-9]+)/u',
        );
        $constantSymbol = $this->optional(
            $text,
            '/(?:^|\R)\s*Konstantní\s+symbol\s*\R\s*(?<value>[0-9]+)/u',
        );

        [$cpAccount, $cpBank] = $this->splitAccount((string) $counterpartyAccount);

        return new ParsedBankEmailNotice(
            variableSymbol: $this->normalizeSymbol((string) $variableSymbol),
            amount: $this->parseAmount((string) $amountCurrency['amount']),
            currency: strtoupper((string) $amountCurrency['currency']),
            postedAt: $this->parseDate($postedAt, 'validní datum účtování'),
            recipientAccount: $recipientAccount,
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            counterpartyName: $counterpartyName,
            constantSymbol: $constantSymbol,
        );
    }

}
