<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\CreditasBankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

final class CreditasBankEmailNoticeParserTest extends TestCase
{
    public function testParsesOutgoingHoldAsNegativeAmount(): void
    {
        $body = <<<TEXT
Hezký den,

zůstatek na účtu 1000000005/2250 se snížil o částku 315,00 CZK (Blokace). Disponibilní zůstatek 23.06.2026 16:39 je 12 489,48 CZK.

Detail platby:
- změna na účtu: 1000000005/2250
- datum: 23.06.2026 16:39
- částka: 315,00 CZK
- disponibilní zůstatek: 12 489,48 CZK

Vaše Banka CREDITAS
TEXT;

        $parser = new CreditasBankEmailNoticeParser();
        $message = $this->message($body, 'Notifikace o změně na účtu');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('', $parsed->variableSymbol);
        self::assertSame(-315.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-06-23', $parsed->postedAt);
        self::assertSame('1000000005/2250', $parsed->recipientAccount);
        self::assertSame('Blokace', $parsed->message);
        self::assertNull($parsed->counterpartyAccount);
    }

    public function testParsesIncomingPaymentWithCounterparty(): void
    {
        $body = <<<TEXT
Hezký den,

zůstatek na účtu 1000000005/2250 se zvýšil o částku 23 000,00 CZK (Příchozí úhrada). Disponibilní zůstatek 23.06.2026 18:41 je 96 163,53 CZK.

Detail platby:
- změna na účtu: 1000000005/2250
- účet protistrany: 1900000007 - banka protistrany: 0800 - datum: 23.06.2026 18:41
- částka: 23 000,00 CZK
- disponibilní zůstatek: 96 163,53 CZK

Vaše Banka CREDITAS
TEXT;

        $parser = new CreditasBankEmailNoticeParser();
        $message = $this->message($body, 'Notifikace o změně na účtu');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('', $parsed->variableSymbol);
        self::assertSame(23000.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-06-23', $parsed->postedAt);
        self::assertSame('1000000005/2250', $parsed->recipientAccount);
        self::assertSame('1900000007', $parsed->counterpartyAccount);
        self::assertSame('0800', $parsed->counterpartyBank);
        self::assertSame('Příchozí úhrada', $parsed->message);
    }

    public function testParsesIncomingPaymentWithVariableSymbol(): void
    {
        $body = <<<TEXT
Hezký den,

zůstatek na účtu 1000000005/2250 se zvýšil o částku 1 000,00 CZK (Příchozí úhrada). Disponibilní zůstatek 23.06.2026 18:43 je 97 163,53 CZK.

Detail platby:
- změna na účtu: 1000000005/2250
- účet protistrany: 1900000007 - banka protistrany: 0800 - datum: 23.06.2026 18:43
- částka: 1 000,00 CZK
- VS: 123456 - disponibilní zůstatek: 97 163,53 CZK

Vaše Banka CREDITAS
TEXT;

        $parser = new CreditasBankEmailNoticeParser();
        $parsed = $parser->parse($this->message($body, 'Notifikace o změně na účtu'), $this->provider($parser));

        self::assertSame('123456', $parsed->variableSymbol);
        self::assertSame(1000.0, $parsed->amount);
        self::assertSame('1900000007', $parsed->counterpartyAccount);
        self::assertSame('0800', $parsed->counterpartyBank);
    }

    public function testSupportsToleratesBrokenDiacritics(): void
    {
        $body = "zustatek na uctu 1000000005/2250 se snizil o castku 315,00 CZK (Blokace).\n"
            . "Detail platby:\n- zmena na uctu: 1000000005/2250\n- datum: 23.06.2026 16:39\n- castka: 315,00 CZK";

        $parser = new CreditasBankEmailNoticeParser();
        $message = $this->message($body, 'Notifikace o zmene na uctu');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));
        $parsed = $parser->parse($message, $provider);
        self::assertSame(-315.0, $parsed->amount);
        self::assertSame('1000000005/2250', $parsed->recipientAccount);
    }

    public function testRejectsSpoofedSenderDomain(): void
    {
        $parser = new CreditasBankEmailNoticeParser();
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<spoof@evil.com>',
            date: new \DateTimeImmutable('2026-06-23 16:39:50'),
            sender: 'Banka CREDITAS <attacker@creditas.cz.evil.com>',
            subject: 'Notifikace o změně na účtu',
            text: "zůstatek na účtu 1000000005/2250 se snížil o částku 1,00 CZK (Blokace).\n- změna na účtu: 1000000005/2250\n- částka: 1,00 CZK",
            raw: '',
        );
        self::assertFalse($parser->supports($message, $this->provider($parser)));
    }

    public function testRejectsForwardedNoticeWhenForwardingNotAllowed(): void
    {
        $parser = new CreditasBankEmailNoticeParser();
        self::assertFalse($parser->supports($this->forwardedMessage(false), $this->provider($parser)));
    }

    public function testSupportsForwardedNoticeWhenForwardingAllowed(): void
    {
        $parser = new CreditasBankEmailNoticeParser();
        $message = $this->forwardedMessage(true);
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);
        self::assertSame('123456', $parsed->variableSymbol);
        self::assertSame(1000.0, $parsed->amount);
        self::assertSame('1000000005/2250', $parsed->recipientAccount);
        self::assertSame('Příchozí úhrada', $parsed->message);
    }

    public function testForwarderWhitelistAcceptsMatchingSenderAndDomain(): void
    {
        $parser = new CreditasBankEmailNoticeParser();
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($this->forwardedMessage(true, 'jan.novak@example.com'), $provider), 'exact address');
        self::assertTrue($parser->supports($this->forwardedMessage(true, 'example.com'), $provider), 'domain');
    }

    public function testForwarderWhitelistRejectsForeignSender(): void
    {
        $parser = new CreditasBankEmailNoticeParser();
        $provider = $this->provider($parser);

        self::assertFalse($parser->supports($this->forwardedMessage(true, 'someone@evil.com'), $provider), 'foreign address');
        self::assertFalse($parser->supports($this->forwardedMessage(true, 'evil.com'), $provider), 'foreign domain');
    }

    /**
     * Přeposlané (FW) avízo: odesílatel je adresa uživatele, ne banky; banku
     * prozradí jen patička v těle. Outlook do textové části nevkládá přeposlaný
     * `From:` blok, proto se banka pozná z adresy info@creditas.cz v patičce.
     */
    private function forwardedMessage(bool $allowForwarded, string $forwardedFrom = ''): BankEmailNoticeMessage
    {
        $body = <<<TEXT
Hezký den,

zůstatek na účtu 1000000005/2250 se zvýšil o částku 1 000,00 CZK (Příchozí úhrada). Disponibilní zůstatek 23.06.2026 18:43 je 97 163,53 CZK.

Detail platby:
- změna na účtu: 1000000005/2250
- účet protistrany: 1900000007 - banka protistrany: 0800 - datum: 23.06.2026 18:43
- částka: 1 000,00 CZK
- VS: 123456 - disponibilní zůstatek: 97 163,53 CZK

Pokud se chcete na cokoli zeptat, napište na e-mail info@creditas.cz.
Vaše Banka CREDITAS
TEXT;

        return new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<forwarded-sample@example.com>',
            date: new \DateTimeImmutable('2026-06-23 18:43:00'),
            sender: 'Jan Novák <jan.novak@example.com>',
            subject: 'FW: Notifikace o změně na účtu',
            text: $body,
            raw: $body,
            authResults: [],
            allowForwarded: $allowForwarded,
            forwardedFrom: $forwardedFrom,
        );
    }

    private function message(string $body, string $subject): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-sample@creditas.cz>',
            date: new \DateTimeImmutable('2026-06-23 16:39:50'),
            sender: 'info@creditas.cz',
            subject: $subject,
            text: $body,
            raw: $body,
        );
    }

    private function provider(CreditasBankEmailNoticeParser $parser): BankEmailNoticeProvider
    {
        $provider = $parser->defaultProvider();
        self::assertInstanceOf(BankEmailNoticeProvider::class, $provider);
        return $provider;
    }
}
