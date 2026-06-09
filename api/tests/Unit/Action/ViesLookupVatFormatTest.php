<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action;

use MyInvoice\Action\AresVies\ViesLookupAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regrese: VIES validace DIČ povolovala po prefixu země jen číslice
 * (/^[A-Z]{2}\d{4,12}$/), takže DIČ s písmenem padalo na "DIČ musí mít prefix
 * země a …". Reálný případ: nizozemské NL…B01 (9 číslic + "B" + 2 číslice).
 * Stejně tak AT (U…), ES, FR, IE. Oprava povoluje i písmena (znaky + a * = starší IE).
 *
 * Čistě unit — predikát nesahá na DB ani VIES, takže action instancujeme bez
 * konstruktoru a privátní isValidVatId() voláme přes reflexi.
 */
final class ViesLookupVatFormatTest extends TestCase
{
    private function isValid(string $vatId): bool
    {
        $action = (new ReflectionClass(ViesLookupAction::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($action, 'isValidVatId');
        /** @var bool $r */
        $r = $m->invoke($action, $vatId);
        return $r;
    }

    public function testAcceptsDutchVatWithLetter(): void
    {
        // NL = 9 číslic + "B" + 2 číslice; dřív padalo na "jen číslice".
        self::assertTrue($this->isValid('NL123456789B01'));
    }

    public function testAcceptsOtherEuFormatsWithLetters(): void
    {
        self::assertTrue($this->isValid('ATU12345678')); // Rakousko (U + 8 číslic)
        self::assertTrue($this->isValid('ESX1234567X')); // Španělsko
        self::assertTrue($this->isValid('IE1234567FA')); // Irsko
    }

    public function testStillAcceptsPlainNumericVat(): void
    {
        self::assertTrue($this->isValid('CZ12345678'));
        self::assertTrue($this->isValid('SK2022638992'));
    }

    public function testRejectsInvalidInput(): void
    {
        self::assertFalse($this->isValid(''));                   // prázdné
        self::assertFalse($this->isValid('12345678'));           // chybí prefix země
        self::assertFalse($this->isValid('NL123456789B01XYZ'));  // moc dlouhé (>12)
        self::assertFalse($this->isValid('N1868351246B01'));     // špatný prefix
    }
}
