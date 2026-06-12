<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\FioBankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

final class FioBankEmailNoticeParserTest extends TestCase
{
    public function testParsesIncomingNotice(): void
    {
        $body = <<<TEXT
Příjem na kontě: 1234560005
Částka: 3 898,00
VS: 26006439
Zpráva příjemci: test potvrzeni
Aktuální zůstatek: 168 459,32
Protiúčet: 7654320005/0800
SS:
KS:
TEXT;

        $parser = new FioBankEmailNoticeParser();
        $message = $this->message($body, 'Fio banka - prijem na konte');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('26006439', $parsed->variableSymbol);
        self::assertSame(3898.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-06-10', $parsed->postedAt);
        self::assertSame('1234560005/2010', $parsed->recipientAccount);
        self::assertSame('7654320005', $parsed->counterpartyAccount);
        self::assertSame('0800', $parsed->counterpartyBank);
        self::assertSame('test potvrzeni', $parsed->message);
        self::assertNull($parsed->constantSymbol);
    }

    public function testParsesOutgoingNoticeAsNegativeAmount(): void
    {
        $body = <<<TEXT
Výdaj na kontě: 1234560005
Částka: 5 000,00
VS: 08111111
US: Najem skladovych prostor
Aktuální zůstatek: 17 449,23
Protiúčet: 7654320005/3030
SS:
KS: 0
TEXT;

        $parser = new FioBankEmailNoticeParser();
        $message = $this->message($body, 'Fio banka - vydaj na konte');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('8111111', $parsed->variableSymbol);
        self::assertSame(-5000.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('1234560005/2010', $parsed->recipientAccount);
        self::assertSame('7654320005', $parsed->counterpartyAccount);
        self::assertSame('3030', $parsed->counterpartyBank);
        self::assertSame('Najem skladovych prostor', $parsed->message);
        self::assertSame('0', $parsed->constantSymbol);
    }

    public function testParsesNoticeWithExplicitCurrencyAndWithoutSymbols(): void
    {
        $body = <<<TEXT
Příjem na kontě: 1234560005
Částka: 1 250,00 EUR
Zpráva příjemci: invoice 2026001
Aktuální zůstatek: 2 500,00
Protiúčet: 7654320005/0100
SS:
KS:
TEXT;

        $parser = new FioBankEmailNoticeParser();
        $parsed = $parser->parse($this->message($body, 'Fio banka - prijem na konte'), $this->provider($parser));

        self::assertSame('', $parsed->variableSymbol);
        self::assertSame(1250.0, $parsed->amount);
        self::assertSame('EUR', $parsed->currency);
        self::assertNull($parsed->constantSymbol);
    }

    public function testRejectsSpoofedSenderDomain(): void
    {
        $parser = new FioBankEmailNoticeParser();
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<spoof@evil.com>',
            date: new \DateTimeImmutable('2026-06-10 23:35:22'),
            sender: 'Fio banka <attacker@fio.cz.evil.com>',
            subject: 'Fio banka - prijem na konte',
            text: "Příjem na kontě: 1234560005\nČástka: 1,00",
            raw: '',
        );
        self::assertFalse($parser->supports($message, $this->provider($parser)));
    }

    public function testThrowsWithoutMessageDate(): void
    {
        $body = "Příjem na kontě: 1234560005\nČástka: 1,00\nVS: 123";
        $parser = new FioBankEmailNoticeParser();
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-sample@fio.cz>',
            date: null,
            sender: 'automat@fio.cz',
            subject: 'Fio banka - prijem na konte',
            text: $body,
            raw: $body,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('datum');
        $parser->parse($message, $this->provider($parser));
    }

    private function message(string $body, string $subject): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-sample@fio.cz>',
            date: new \DateTimeImmutable('2026-06-10 23:35:22'),
            sender: 'automat@fio.cz',
            subject: $subject,
            text: $body,
            raw: $body,
        );
    }

    private function provider(FioBankEmailNoticeParser $parser): BankEmailNoticeProvider
    {
        $provider = $parser->defaultProvider();
        self::assertInstanceOf(BankEmailNoticeProvider::class, $provider);
        return $provider;
    }
}
