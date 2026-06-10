<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use MyInvoice\Service\Pdf\PdfImageExtractor;
use PHPUnit\Framework\TestCase;

final class PdfImageExtractorTest extends TestCase
{
    private PdfImageExtractor $extractor;

    protected function setUp(): void
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('gzcompress')) {
            self::markTestSkipped('GD / zlib není dostupné.');
        }
        $this->extractor = new PdfImageExtractor();
    }

    public function testPicksSquareBilevelImage(): void
    {
        // QR-like: 120×120, šachovnice (≈50 % černé, bilevel).
        $qr = $this->grayImage(120, 120, static fn (int $x, int $y): int => (($x >> 2) + ($y >> 2)) % 2 === 0 ? 0 : 255);
        // Distraktor: nečtvercové logo 240×60 — musí být odfiltrováno.
        $logo = $this->grayImage(240, 60, static fn (int $x, int $y): int => 200);

        $pdf = "%PDF-1.4\n"
            . $this->imageObject(1, 120, 120, $qr)
            . $this->imageObject(2, 240, 60, $logo)
            . "%%EOF";

        $uri = $this->extractor->findQrLikeImage($pdf);

        self::assertNotNull($uri, 'Měl by najít čtvercový bilevel obrázek.');
        self::assertStringStartsWith('data:image/png;base64,', $uri);

        // Dekóduj zpět — ověř, že to je vrácený 120×120 QR-like obrázek.
        $png = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
        self::assertNotFalse($png);
        $gd = imagecreatefromstring((string) $png);
        self::assertInstanceOf(\GdImage::class, $gd);
        self::assertSame(120, imagesx($gd));
        self::assertSame(120, imagesy($gd));
    }

    public function testIgnoresNonSquareOnly(): void
    {
        $logo = $this->grayImage(240, 60, static fn (int $x, int $y): int => (($x >> 2) % 2 === 0) ? 0 : 255);
        $pdf = "%PDF-1.4\n" . $this->imageObject(1, 240, 60, $logo) . "%%EOF";
        self::assertNull($this->extractor->findQrLikeImage($pdf));
    }

    public function testIgnoresAllWhiteSquare(): void
    {
        $white = $this->grayImage(120, 120, static fn (int $x, int $y): int => 255);
        $pdf = "%PDF-1.4\n" . $this->imageObject(1, 120, 120, $white) . "%%EOF";
        self::assertNull($this->extractor->findQrLikeImage($pdf));
    }

    public function testNonPdfReturnsNull(): void
    {
        self::assertNull($this->extractor->findQrLikeImage('not a pdf'));
        self::assertNull($this->extractor->findQrLikeImage(''));
    }

    /** Raw 8bpc DeviceGray vzorky (row-major, bez paddingu). */
    private function grayImage(int $w, int $h, callable $pix): string
    {
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $raw .= chr(((int) $pix($x, $y)) & 0xFF);
            }
        }
        return $raw;
    }

    /** Image XObject (DeviceGray 8bpc, FlateDecode, bez prediktoru). */
    private function imageObject(int $id, int $w, int $h, string $raw): string
    {
        $comp = gzcompress($raw, 6);
        return "{$id} 0 obj\n"
            . "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} "
            . "/ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen((string) $comp) . " >>\n"
            . "stream\n" . $comp . "\nendstream\nendobj\n";
    }
}
