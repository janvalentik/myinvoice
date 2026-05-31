<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IdokladClient;
use PHPUnit\Framework\TestCase;

/**
 * Regrese #80 — rozbalení iDoklad v3 ApiResult envelope.
 *
 * iDoklad v3 vrací list jako { "Data": { "Items": [...], "TotalItems": N, "TotalPages": M }, ... }.
 * Dřív se za „Items" omylem bralo celé Data (Page wrapper se 3 klíči), takže import
 * iteroval klíče Items/TotalItems/TotalPages → vždy „3 záznamy", vytvořeno 0.
 */
final class IdokladClientParseTest extends TestCase
{
    public function testUnwrapsPagedEnvelope(): void
    {
        $resp = [
            'Data' => [
                'Items' => [
                    ['Id' => 1, 'CompanyName' => 'Acme'],
                    ['Id' => 2, 'CompanyName' => 'Beta'],
                ],
                'TotalItems' => 247,
                'TotalPages' => 3,
            ],
            'IsSuccess' => true,
            'ErrorCode' => 0,
            'Message'   => null,
        ];

        $parsed = IdokladClient::parseListResponse($resp);

        self::assertCount(2, $parsed['Items']);
        self::assertSame(1, $parsed['Items'][0]['Id']);
        self::assertSame(247, $parsed['TotalItems']);
        self::assertSame(3, $parsed['TotalPages']);
    }

    public function testDoesNotMistakePageWrapperKeysForItems(): void
    {
        // Přesně payload, který způsobil #80: 3 klíče Page wrapperu nesmí být „3 záznamy".
        $resp = [
            'Data' => [
                'Items'      => [],
                'TotalItems' => 0,
                'TotalPages' => 0,
            ],
            'IsSuccess' => true,
        ];

        $parsed = IdokladClient::parseListResponse($resp);

        self::assertSame([], $parsed['Items']);
        self::assertSame(0, $parsed['TotalItems']);
    }

    public function testHandlesBareListData(): void
    {
        // Endpointy typu Attachments vrací Data rovnou jako pole.
        $resp = [
            'Data' => [
                ['Id' => 10, 'FileName' => 'a.pdf'],
                ['Id' => 11, 'FileName' => 'b.pdf'],
            ],
            'IsSuccess' => true,
        ];

        $parsed = IdokladClient::parseListResponse($resp);

        self::assertCount(2, $parsed['Items']);
        self::assertSame(10, $parsed['Items'][0]['Id']);
        self::assertSame(2, $parsed['TotalItems']);
    }

    public function testHandlesLegacyTopLevelItems(): void
    {
        // Defenzivně: pokud by někdy přišel list bez envelope.
        $resp = [
            'Items'      => [['Id' => 5]],
            'TotalItems' => 1,
            'TotalPages' => 1,
        ];

        $parsed = IdokladClient::parseListResponse($resp);

        self::assertCount(1, $parsed['Items']);
        self::assertSame(5, $parsed['Items'][0]['Id']);
        self::assertSame(1, $parsed['TotalItems']);
    }
}
