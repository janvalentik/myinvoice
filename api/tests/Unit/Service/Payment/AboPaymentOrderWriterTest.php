<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Payment;

use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use PHPUnit\Framework\TestCase;

/**
 * Zlatý test ABO/KPC generátoru — struktura ověřená proti reálnému
 * `private/KPC/abo-payment-96.kpc` (data jsou zde SYNTETICKÁ, ne z výpisu).
 */
final class AboPaymentOrderWriterTest extends TestCase
{
    private AboPaymentOrderWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new AboPaymentOrderWriter();
    }

    public function testBuildsFullAboPaymentOrder(): void
    {
        $order = [
            'client_name'          => 'TESTCLIENT',
            'client_number'        => null,                 // → odvodit z čísla účtu plátce
            'file_number'          => '123',
            'payer_account_number' => '19-2000145399',
            'payer_bank_code'      => '0800',
            'payment_date'         => '2026-06-12',
            'items'                => [
                [
                    'account_number'  => '2000145399',
                    'bank_code'       => '0800',
                    'amount'          => 4555.40,
                    'variable_symbol' => '2601603',
                    'constant_symbol' => '0308',
                    'specific_symbol' => null,
                    'message'         => 'FV-160/2026',     // „-" a „/" se z platebního styku vyhodí
                ],
                [
                    'account_number'  => '35-6233260257',
                    'bank_code'       => '0100',
                    'amount'          => 15000.00,
                    'variable_symbol' => '260100001',
                    'constant_symbol' => null,              // KS = jen banka + 0000
                    'specific_symbol' => '7',
                    'message'         => null,              // → fallback na VS
                ],
            ],
        ];

        $expected = implode("\r\n", [
            'UHL1120626TESTCLIENT          2000145399000999000000000000',
            '1 1501 000123 0800',
            '2 000019-2000145399 00000001955540 120626',
            '000000-2000145399 000000455540 2601603 08000308 0000000000 AV:FV1602026',
            '000035-6233260257 000001500000 260100001 01000000 0000000007 AV:260100001',
            '3 +',
            '5 +',
        ]) . "\r\n";

        self::assertSame($expected, $this->writer->build($order));
    }

    /** KS pole = směrový kód banky příjemce (4) + konstantní symbol (4). */
    public function testConstantSymbolFieldEncodesRecipientBankCode(): void
    {
        $out = $this->writer->build($this->orderWith([
            'account_number' => '123456789', 'bank_code' => '2700',
            'amount' => 100.00, 'variable_symbol' => '1', 'constant_symbol' => '308',
        ]));
        // banka 2700 + KS 0308 → '27000308'
        self::assertStringContainsString(' 27000308 ', $out);
    }

    /** Částka se uvádí v haléřích, zleva nulami, 12 míst u položky. */
    public function testAmountInHalerWithCorrectRounding(): void
    {
        $out = $this->writer->build($this->orderWith([
            'account_number' => '123456789', 'bank_code' => '0800',
            'amount' => 1234.565, 'variable_symbol' => '1', // 123456.5 haléře → round 123457
        ]));
        self::assertStringContainsString(' 000000123457 ', $out);
    }

    public function testThrowsOnEmptyItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build([
            'payer_account_number' => '2000145399', 'payer_bank_code' => '0800',
            'payment_date' => '2026-06-12', 'items' => [],
        ]);
    }

    public function testThrowsWhenPayeeHasNoCzechAccount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build($this->orderWith([
            'account_number' => null, 'bank_code' => null, // jen IBAN → do ABO nepatří
            'amount' => 100.00, 'variable_symbol' => '1',
        ]));
    }

    public function testThrowsOnNonPositiveAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build($this->orderWith([
            'account_number' => '123456789', 'bank_code' => '0800',
            'amount' => 0, 'variable_symbol' => '1',
        ]));
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function orderWith(array $item): array
    {
        return [
            'client_name'          => 'X',
            'payer_account_number' => '2000145399',
            'payer_bank_code'      => '0800',
            'payment_date'         => '2026-06-12',
            'items'                => [$item],
        ];
    }
}
