<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use PHPUnit\Framework\TestCase;

final class EmailNoticeTextNormalizerTest extends TestCase
{
    /**
     * issue #58: tělo ČSOB avíza ve windows-1250 musí po normalizaci zachovat
     * diakritiku (dřív `iconv UTF-8//IGNORE` diakritiku zahodil).
     */
    public function testRecoversWindows1250HtmlBody(): void
    {
        $utf8Html = '<html><body><p>Parametry platby</p>'
            . '<p>Účet<br>123456789/0300</p>'
            . '<p>Částka<br>+10 000,00 CZK</p>'
            . '<p>Vaše ČSOB</p></body></html>';
        $cp1250 = iconv('UTF-8', 'WINDOWS-1250', $utf8Html);
        self::assertIsString($cp1250);

        $out = (new EmailNoticeTextNormalizer())->normalize($cp1250);

        self::assertStringContainsString('Parametry platby', $out);
        self::assertStringContainsString('Účet', $out);
        self::assertStringContainsString('Částka', $out);
        self::assertStringContainsString('Vaše ČSOB', $out);
        self::assertStringNotContainsString('<', $out);
    }

    public function testKeepsUtf8PlainBody(): void
    {
        $body = "Parametry platby\nČástka\n+10 000,00 CZK\nVaše ČSOB";
        $out = (new EmailNoticeTextNormalizer())->normalize($body);
        self::assertStringContainsString('Částka', $out);
        self::assertStringContainsString('Vaše ČSOB', $out);
    }

    /**
     * issue #158: ČS avízo „Odešla platba" má v patičce marketingové URL
     * s neúmyslnými „=XX" sekvencemi (tracking odkaz: „…&id=0729…&source-id=aauesx").
     * Bezpodmínečný quoted_printable_decode je rozkódoval (=aa → 0xAA), čímž
     * rozbil validitu UTF-8 → EmailCharsetNormalizer pak celé tělo překlopil
     * jako windows-1250 a diakritika se zdvojila na mojibake („Směr"→„SmÄ›r").
     * Po opravě musí už platné UTF-8 tělo zůstat netknuté.
     */
    public function testDoesNotDoubleDecodeQuotedPrintableInFooterUrls(): void
    {
        $body = "Směr platby: odchozí\n"
            . "Variabilní symbol: 8\n"
            . "Částka v měně účtu: 8 157,70 Kč\n"
            . 'Sledujte náš web '
            . '<https://www.csas.cz/webapi/api/v1/tracking/trackMessage?'
            . 'web-api-key=142c3916-adc0-4f6c-a65b-45ab827d94a5&api_key=aaaa'
            . '&id=0729217100000012&source-id=aauesx&source-name=SAC_OMNIC_CORE>';

        $out = (new EmailNoticeTextNormalizer())->normalize($body);

        self::assertStringContainsString('Směr platby: odchozí', $out);
        self::assertStringContainsString('Variabilní symbol', $out);
        self::assertStringContainsString('Částka v měně účtu', $out);
        self::assertStringNotContainsString('SmÄ', $out);
    }
}
