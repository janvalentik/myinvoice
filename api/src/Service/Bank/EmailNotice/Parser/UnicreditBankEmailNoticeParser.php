<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class UnicreditBankEmailNoticeParser extends AbstractBankEmailNoticeParser
{
    public function key(): string
    {
        return 'unicredit';
    }

    protected function parserLabel(): string
    {
        return 'UniCredit Bank';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'system:' . $this->key(),
            code: $this->key(),
            name: 'UniCredit Bank - Informace o pohybu na účtu',
            parserType: $this->key(),
            enabled: true,
            senderWhitelist: 'unicreditbank@unicreditgroup.cz noe@unicredit.eu',
            subjectPattern: 'Informace\\s+o\\s+pohybu\\s+na\\s+účtu|Informace\\s+o\\s+pohybu\\s+na\\s+uctu',
            bodyPattern: 'UniCredit\\s+Bank',
            fieldPatterns: [],
            normalizerConfig: [],
            system: true,
        );
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!$this->senderMatchesDomain($message, 'unicreditgroup.cz', 'unicredit.eu')) {
            return false;
        }

        $subject = $this->compact(mb_strtolower(str_replace('_', ' ', $message->subject), 'UTF-8'));
        if (
            !str_contains($subject, 'informace o pohybu na účtu')
            && !str_contains($subject, 'informace o pohybu na uctu')
        ) {
            return false;
        }

        $text = $this->compact(mb_strtolower($this->normalizeText($message->text), 'UTF-8'));
        return (str_contains($text, 'variabilní symbol') || str_contains($text, 'variabilni symbol'))
            && (str_contains($text, 'číslo účtu protistrany') || str_contains($text, 'cislo uctu protistrany'))
            && str_contains($text, 'unicredit bank');
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizeText($message->text);

        $recipientAccount = $this->required(
            $text,
            '/na\s+Va[šs]em\s+[úu][čc]tu\s+[čc]\.\s*(?<value>[0-9\-]+)/iu',
            'cílový účet',
        );
        $amountCurrency = $this->match($text, '/[ČC]ástka:\s*(?<amount>[+\-]?[0-9,. ]+)\s*(?<currency>[A-Z]{3})/u');
        if ($amountCurrency === null) {
            throw new \RuntimeException('UniCredit Bank parser nenašel částku a měnu.');
        }
        $counterpartyAccount = $this->optional(
            $text,
            '/[ČC]íslo\s+[úu][čc]tu\s+protistrany:\s*(?<value>[0-9\-]+\/[0-9]{4})/iu',
        );
        $counterpartyName = $this->optional(
            $text,
            '/N[áa]zev\s+[úu][čc]tu\s+protistrany:\s*(?<value>.*?)\s*Variabiln[íi]\s+symbol:/isu',
        );
        $variableSymbol = $this->required(
            $text,
            '/Variabiln[íi]\s+symbol:\s*(?<value>[0-9]+)/iu',
            'variabilní symbol',
        );
        $messageText = $this->optional(
            $text,
            '/Detaily\s+transakce:\s*(?<value>.*?)\s*Datum:/isu',
        );
        $postedAt = $this->required(
            $text,
            '/Datum:\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4}\s+\d{1,2}:\d{2}(?::\d{2})?)/u',
            'datum',
        );

        [$cpAccount, $cpBank] = $this->splitAccount((string) $counterpartyAccount);

        return new ParsedBankEmailNotice(
            variableSymbol: $this->normalizeSymbol($variableSymbol),
            amount: $this->parseAmount((string) $amountCurrency['amount']),
            currency: strtoupper((string) $amountCurrency['currency']),
            postedAt: $this->parseDate($postedAt),
            recipientAccount: $recipientAccount,
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            counterpartyName: $counterpartyName,
            message: $messageText,
        );
    }

}
