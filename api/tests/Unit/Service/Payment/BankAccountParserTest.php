<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Payment;

use MyInvoice\Service\Payment\BankAccountParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BankAccountParserTest extends TestCase
{
    private BankAccountParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BankAccountParser();
    }

    /**
     * @param array<string,string> $expected
     */
    #[DataProvider('parseCases')]
    public function testParse(?string $raw, array $expected): void
    {
        self::assertSame($expected, $this->parser->parse($raw));
    }

    /** @return iterable<string, array{?string, array<string,string>}> */
    public static function parseCases(): iterable
    {
        yield 'plain CZ account'      => ['1000000005/0100', ['account_number' => '1000000005', 'bank_code' => '0100']];
        yield 'CZ account w/ prefix'  => ['19-2000145399/0800', ['account_number' => '19-2000145399', 'bank_code' => '0800']];
        yield 'spaces around sep'     => ['19 - 2000145399 / 0800', ['account_number' => '19-2000145399', 'bank_code' => '0800']];
        yield 'account in text'       => ['č. ú. 1000000005/0100', ['account_number' => '1000000005', 'bank_code' => '0100']];
        yield 'IBAN compact'          => ['CZ6508000000192000145399', ['iban' => 'CZ6508000000192000145399']];
        yield 'IBAN with spaces'      => ['CZ65 0800 0000 1920 0014 5399', ['iban' => 'CZ6508000000192000145399']];
        yield 'IBAN lowercase'        => ['cz6508000000192000145399', ['iban' => 'CZ6508000000192000145399']];
        yield 'IBAN in text'          => ['IBAN: CZ6508000000192000145399', ['iban' => 'CZ6508000000192000145399']];
        yield 'invalid IBAN mod97'    => ['CZ0008000000192000145399', []];
        yield 'garbage'               => ['nějaký text bez účtu', []];
        yield 'empty'                 => ['', []];
        yield 'null'                  => [null, []];
    }

    public function testBankSnapshotPassesThrough(): void
    {
        self::assertSame(
            ['account_number' => '1000000005', 'bank_code' => '0100', 'iban' => '', 'bic' => ''],
            $this->parser->bankSnapshot('1000000005', '0100', null, null),
        );
    }

    public function testHasAccount(): void
    {
        self::assertTrue($this->parser->hasAccount('1000000005', '0100', null));
        self::assertTrue($this->parser->hasAccount(null, null, 'CZ6508000000192000145399'));
        self::assertFalse($this->parser->hasAccount('1000000005', null, null)); // bank code chybí
        self::assertFalse($this->parser->hasAccount(null, null, null));
        self::assertFalse($this->parser->hasAccount('', '', ''));
    }
}
