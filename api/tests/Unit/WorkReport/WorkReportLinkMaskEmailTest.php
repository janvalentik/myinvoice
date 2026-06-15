<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\WorkReport;

use MyInvoice\Service\WorkReport\WorkReportLinkService;
use PHPUnit\Framework\TestCase;

/**
 * Maskování e-mailu pro veřejnou stránku — bez DB, čistá statická funkce.
 */
final class WorkReportLinkMaskEmailTest extends TestCase
{
    public function testMasksLocalPart(): void
    {
        // 'radek' = 5 znaků → první písmeno + 4 hvězdičky
        self::assertSame('r****@hulan.cz', WorkReportLinkService::maskEmail('radek@hulan.cz'));
    }

    public function testSingleCharLocalPart(): void
    {
        self::assertSame('a*@x.cz', WorkReportLinkService::maskEmail('a@x.cz'));
    }

    public function testInvalidWithoutAt(): void
    {
        self::assertSame('***', WorkReportLinkService::maskEmail('neni-email'));
    }

    public function testKeepsDomainIntact(): void
    {
        $masked = WorkReportLinkService::maskEmail('ucetni@firma-s-r-o.cz');
        self::assertStringEndsWith('@firma-s-r-o.cz', $masked);
        self::assertStringStartsWith('u', $masked);
    }
}
