<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\RegexBankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

final class RegexBankEmailNoticeParserCsTest extends TestCase
{
    /**
     * @return array<string,string>
     */
    private function csFieldPatterns(): array
    {
        return [
            'recipient_account' => 'Číslo účtu:\s*(?<value>[0-9\-]+\/[0-9]{4})',
            'counterparty_account' => 'Číslo účtu protistrany:\s*(?<value>[0-9\-]+\/[0-9]{4})',
            'amount' => 'Částka v měně účtu:\s*(?<value>[0-9 .]+,[0-9]{2})',
            'currency' => 'Částka v měně účtu:\s*[0-9 .]+,[0-9]{2}\s*(?<value>Kč|CZK|EUR|USD|€)',
            'variable_symbol' => 'Variabilní symbol:\s*(?<value>[0-9]+)',
            'constant_symbol' => 'Konstantní symbol:\s*(?<value>[0-9]+)',
            'direction' => 'Směr platby:\s*(?<value>příchozí|odchozí)',
        ];
    }

    private function csProvider(): array
    {
        return [
            'parser_type' => 'regex',
            'sender_whitelist' => '',
            'subject_pattern' => '',
            'body_pattern' => '',
            'field_patterns' => $this->csFieldPatterns(),
        ];
    }

    private function csMessage(string $body): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<cs-sample@csas.cz>',
            date: new \DateTimeImmutable('2026-06-03 09:30:00'),
            sender: 'Česká spořitelna <automat@csas.cz>',
            subject: 'Avízo o pohybu na účtu',
            text: $body,
            raw: $body,
        );
    }

    public function testParsesIncomingCsNotice(): void
    {
        $body = <<<TEXT
Dobrý den, pane Nováku,

na účet SIMPLE NETWORKS 6509175329/0800 právě dorazila platba ve výši 10,00 Kč.

Na účtu je nově k dispozici x xxx xxx,xx Kč (včetně kontokorentu x xxx xxx,xx Kč).

Vaše Česká spořitelna

Informace o transakci

Směr platby: příchozí
Číslo účtu: 6509175329/0800

Číslo účtu protistrany: 2801836907/2010

Částka v měně transakce: 10,00 Kč
Částka v měně účtu: 10,00 Kč

Variabilní symbol: 123456789
Konstantní symbol: 0
TEXT;

        $parser = new RegexBankEmailNoticeParser();
        $message = $this->csMessage($body);
        $provider = $this->csProvider();

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('123456789', $parsed->variableSymbol);
        self::assertSame(10.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);           // „Kč" → CZK
        self::assertSame('2026-06-03', $parsed->postedAt);    // fallback na datum e-mailu
        self::assertSame('6509175329/0800', $parsed->recipientAccount);
        self::assertSame('2801836907', $parsed->counterpartyAccount);
        self::assertSame('2010', $parsed->counterpartyBank);
        self::assertSame('0', $parsed->constantSymbol);
    }

    public function testOutgoingNoticeGetsNegativeAmount(): void
    {
        $body = <<<TEXT
Informace o transakci

Směr platby: odchozí
Číslo účtu: 6509175329/0800

Číslo účtu protistrany: 2801836907/2010

Částka v měně účtu: 1 234,50 Kč

Variabilní symbol: 555
Konstantní symbol: 0
TEXT;

        $parser = new RegexBankEmailNoticeParser();
        $parsed = $parser->parse($this->csMessage($body), $this->csProvider());

        self::assertSame(-1234.50, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
    }
}
