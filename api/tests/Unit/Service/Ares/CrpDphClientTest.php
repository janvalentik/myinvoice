<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ares;

use MyInvoice\Service\Ares\CrpDphClient;
use PHPUnit\Framework\TestCase;

/**
 * Pokrývá parser odpovědi CRPDPH (getStatusNespolehlivyPlatce) bez síťového volání.
 */
final class CrpDphClientTest extends TestCase
{
    /** Plná odpověď: spolehlivý plátce + 3 zveřejněné účty (atributová forma). */
    public function testParsesStandardAndIbanAccountsAttributeForm(): void
    {
        $xml = $this->envelope('21370362', 'NE', <<<ACC
            <zverejneneUcty>
              <ucet datumZverejneni="2013-04-01">
                <standardniUcet cislo="2000145399" kodBanky="0800"/>
              </ucet>
              <ucet datumZverejneni="2014-01-01">
                <standardniUcet predcisli="19" cislo="2000145399" kodBanky="0800"/>
              </ucet>
              <ucet datumZverejneni="2015-01-01">
                <nestandardniUcet cislo="CZ6508000000192000145399"/>
              </ucet>
            </zverejneneUcty>
            ACC);

        $r = CrpDphClient::parseResponse($xml, '21370362');

        self::assertTrue($r['found']);
        self::assertFalse($r['unreliable']);
        self::assertSame('451', $r['fu_code']); // cisloFu z odpovědi
        self::assertCount(3, $r['accounts']);

        self::assertSame('2000145399/0800', $r['accounts'][0]['display']);
        self::assertSame('', $r['accounts'][0]['prefix']);

        self::assertSame('19-2000145399/0800', $r['accounts'][1]['display']);
        self::assertSame('19', $r['accounts'][1]['prefix']);
        self::assertSame('2000145399', $r['accounts'][1]['number']);
        self::assertSame('0800', $r['accounts'][1]['bank_code']);

        self::assertSame('CZ6508000000192000145399', $r['accounts'][2]['iban']);
        self::assertSame('CZ6508000000192000145399', $r['accounts'][2]['display']);
    }

    /** Některé implementace vrací data jako child elementy místo atributů — fallback. */
    public function testParsesElementForm(): void
    {
        $xml = $this->envelope('12345678', 'NE', <<<ACC
            <zverejneneUcty>
              <ucet>
                <standardniUcet>
                  <predcisli>123</predcisli>
                  <cislo>987654321</cislo>
                  <kodBanky>0100</kodBanky>
                </standardniUcet>
              </ucet>
            </zverejneneUcty>
            ACC);

        $r = CrpDphClient::parseResponse($xml, '12345678');

        self::assertCount(1, $r['accounts']);
        self::assertSame('123-987654321/0100', $r['accounts'][0]['display']);
    }

    /** Fallback: starší/jiné schéma s atributem IBAN= na nestandardniUcet. */
    public function testIbanAttributeFallback(): void
    {
        $xml = $this->envelope('87654321', 'NE', <<<ACC
            <zverejneneUcty>
              <ucet><nestandardniUcet IBAN="CZ6508000000192000145399"/></ucet>
            </zverejneneUcty>
            ACC);

        $r = CrpDphClient::parseResponse($xml, '87654321');

        self::assertCount(1, $r['accounts']);
        self::assertSame('CZ6508000000192000145399', $r['accounts'][0]['iban']);
    }

    public function testUnreliablePayerAno(): void
    {
        $xml = $this->envelope('11111111', 'ANO', '<zverejneneUcty/>');
        $r = CrpDphClient::parseResponse($xml, '11111111');

        self::assertTrue($r['found']);
        self::assertTrue($r['unreliable']);
        self::assertSame([], $r['accounts']);
    }

    public function testNotFound(): void
    {
        $xml = $this->envelope('99999999', 'NENALEZEN', '');
        $r = CrpDphClient::parseResponse($xml, '99999999');

        self::assertFalse($r['found']);
        self::assertNull($r['unreliable']);
        self::assertSame([], $r['accounts']);
    }

    /** Více DIČ v odpovědi — vybere ten dotázaný, ne první. */
    public function testSelectsMatchingDic(): void
    {
        $blocks =
            '<statusPlatceDPH nespolehlivyPlatce="ANO" dic="11111111" cisloFu="1"><zverejneneUcty/></statusPlatceDPH>'
            . '<statusPlatceDPH nespolehlivyPlatce="NE" dic="22222222" cisloFu="2">'
            . '<zverejneneUcty><ucet><standardniUcet cislo="555" kodBanky="0300"/></ucet></zverejneneUcty>'
            . '</statusPlatceDPH>';
        $xml = $this->wrap($blocks);

        $r = CrpDphClient::parseResponse($xml, '22222222');

        self::assertFalse($r['unreliable']);
        self::assertSame('2', $r['fu_code']); // cisloFu z odpovídajícího DIČ bloku
        self::assertCount(1, $r['accounts']);
        self::assertSame('555/0300', $r['accounts'][0]['display']);
    }

    public function testEmptyAndGarbageXml(): void
    {
        foreach (['', '   ', 'not xml at all', '<html><body>503</body></html>'] as $bad) {
            $r = CrpDphClient::parseResponse($bad, '12345678');
            self::assertFalse($r['found'], "bad input: {$bad}");
            self::assertNull($r['unreliable']);
            self::assertSame([], $r['accounts']);
        }
    }

    /** Jedna statusPlatceDPH s daným DIČ + obsahem účtů. */
    private function envelope(string $dic, string $status, string $accountsXml): string
    {
        $block = sprintf(
            '<statusPlatceDPH nespolehlivyPlatce="%s" dic="%s" cisloFu="451">%s</statusPlatceDPH>',
            $status,
            $dic,
            $accountsXml
        );
        return $this->wrap($block);
    }

    private function wrap(string $statusBlocks): string
    {
        // Default namespace na response (bez prefixu) — local-name() to musí zvládnout.
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
              <soapenv:Body>
                <StatusNespolehlivyPlatceResponse xmlns="http://adis.mfcr.cz/rozhraniCRPDPH/">
                  <status statusCode="0" statusText="OK" odpovedGenerovana="2026-05-30T12:00:00"/>
                  {$statusBlocks}
                </StatusNespolehlivyPlatceResponse>
              </soapenv:Body>
            </soapenv:Envelope>
            XML;
    }
}
