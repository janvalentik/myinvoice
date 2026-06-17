<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
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

    private function csProvider(): BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'test:regex-cs',
            code: 'regex_cs',
            name: 'Regex ČS test',
            parserType: 'regex',
            enabled: true,
            senderWhitelist: null,
            subjectPattern: null,
            bodyPattern: null,
            fieldPatterns: $this->csFieldPatterns(),
            normalizerConfig: [],
            system: false,
        );
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

    /**
     * #110: šablona „Odešla platba" nemusí mít v bloku transakce řádek „Číslo účtu:" —
     * vlastní účet se pak doplní fallbackem z úvodní věty „z účtu … odešla platba".
     */
    public function testOutgoingNoticeWithoutRecipientAccountLineFallsBackToIntroSentence(): void
    {
        $body = <<<TEXT
Dobrý den, pane Nováku,

z účtu SIMPLE NETWORKS 6509175329/0800 právě odešla platba ve výši 1 234,50 Kč.

Na účtu je nově k dispozici x xxx xxx,xx Kč.

Vaše Česká spořitelna

Informace o transakci

Směr platby: odchozí

Číslo účtu protistrany: 2801836907/2010

Částka v měně účtu: 1 234,50 Kč

Variabilní symbol: 555
Konstantní symbol: 0
TEXT;

        $parser = new RegexBankEmailNoticeParser();
        $parsed = $parser->parse($this->csMessage($body), $this->csProvider());

        self::assertSame('6509175329/0800', $parsed->recipientAccount);
        self::assertSame(-1234.50, $parsed->amount);
        self::assertSame('555', $parsed->variableSymbol);
        self::assertSame('2801836907', $parsed->counterpartyAccount);
        self::assertSame('2010', $parsed->counterpartyBank);
    }

    /**
     * #147: nová šablona ČS „Odešla platba" uvádí v bloku transakce řádky
     * „Z účtu:" (vlastní) a „Na účet:" (protistrana) místo „Číslo účtu:" /
     * „Číslo účtu protistrany:". Datum v těle není → posted_at z data e-mailu.
     */
    public function testParsesNewOutgoingTemplateWithZuctuNaucetLines(): void
    {
        $body = <<<TEXT
Dobrý den, pane Nováku,
z účtu Jan Novák 6509175329/0800 odešla platba ve výši 70 971,67 Kč.
Na účtu zůstává k dispozici 12 345 Kč (včetně kontokorentu 0 Kč).
Vaše Česká spořitelna
Informace o transakci
Směr platby: odchozí
Z účtu: 6509175329/0800
Na účet: 2801836907/2010
Částka v měně transakce: 70 971,67 Kč
Částka v měně účtu: 70 971,67 Kč
Variabilní symbol: 326005140
Konstantní symbol: 0
Zpráva pro příjemce: AV:326004606
TEXT;

        $parser = new RegexBankEmailNoticeParser();
        $message = $this->csMessage($body);
        $provider = $this->csProvider();

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('326005140', $parsed->variableSymbol);
        self::assertSame(-70971.67, $parsed->amount);            // odchozí → záporná
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-06-03', $parsed->postedAt);       // fallback na datum e-mailu
        self::assertSame('6509175329/0800', $parsed->recipientAccount); // vlastní = „Z účtu" u odchozí
        self::assertSame('2801836907', $parsed->counterpartyAccount);   // protistrana = „Na účet" u odchozí
        self::assertSame('2010', $parsed->counterpartyBank);
        self::assertSame('0', $parsed->constantSymbol);
    }

    /**
     * #147: u příchozí platby se role řádků „Z účtu:" / „Na účet:" prohazují —
     * vlastní účet je „Na účet", protistrana „Z účtu".
     */
    public function testParsesNewIncomingTemplateSwapsAccountRoles(): void
    {
        $body = <<<TEXT
Dobrý den, pane Nováku,
na účet Jan Novák 6509175329/0800 dorazila platba ve výši 500,00 Kč.
Vaše Česká spořitelna
Informace o transakci
Směr platby: příchozí
Z účtu: 2801836907/2010
Na účet: 6509175329/0800
Částka v měně účtu: 500,00 Kč
Variabilní symbol: 111
Konstantní symbol: 0
TEXT;

        $parser = new RegexBankEmailNoticeParser();
        $parsed = $parser->parse($this->csMessage($body), $this->csProvider());

        self::assertSame(500.0, $parsed->amount);               // příchozí → kladná
        self::assertSame('6509175329/0800', $parsed->recipientAccount); // vlastní = „Na účet" u příchozí
        self::assertSame('2801836907', $parsed->counterpartyAccount);   // protistrana = „Z účtu" u příchozí
        self::assertSame('2010', $parsed->counterpartyBank);
        self::assertSame('111', $parsed->variableSymbol);
    }
}
