<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use MyInvoice\Service\Pdf\PdfLogoFlattener;
use PHPUnit\Framework\TestCase;

final class PdfLogoFlattenerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD není dostupné.');
        }
        $this->dir = sys_get_temp_dir() . '/mi-logo-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** Truecolor RGBA PNG s průhlednými ČERNÝMI pixely (== nahrané logo z issue #152). */
    private function makeTransparentBlackPng(string $path): void
    {
        $im = imagecreatetruecolor(40, 20);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); // transparent black
        imagefilledrectangle($im, 0, 0, 10, 10, imagecolorallocate($im, 0x3B, 0x2D, 0x83));
        imagepng($im, $path);
    }

    private function pngColorType(string $path): int
    {
        return ord(substr((string) file_get_contents($path), 25, 1));
    }

    public function testFlattensTransparentTruecolorPngOntoWhite(): void
    {
        $src = $this->dir . '/sup-1.png';
        $this->makeTransparentBlackPng($src);
        self::assertSame(6, $this->pngColorType($src), 'zdroj je truecolor+alpha (ct 6)');

        $out = PdfLogoFlattener::flattenedPath($src);

        self::assertNotSame($src, $out);
        self::assertStringEndsWith('.pdf.png', $out);
        self::assertFileExists($out);
        // Výstup nemá alfa kanál (ct 2) → mPDF nezkouší rozbitý SMask.
        self::assertSame(2, $this->pngColorType($out), 'výstup je truecolor bez alfa (ct 2)');

        // Dříve průhledný (černý) roh je teď bílý, ne černý.
        $im = imagecreatefrompng($out);
        $rgb = imagecolorat($im, 39, 19);
        self::assertSame([255, 255, 255], [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF]);
        // Neprůhledný brand pixel zůstal zachován.
        $rgb2 = imagecolorat($im, 2, 2);
        self::assertSame([0x3B, 0x2D, 0x83], [($rgb2 >> 16) & 0xFF, ($rgb2 >> 8) & 0xFF, $rgb2 & 0xFF]);
    }

    public function testCachesAndIsSelfHealingOnReupload(): void
    {
        $src = $this->dir . '/sup-2.png';
        $this->makeTransparentBlackPng($src);

        $out1 = PdfLogoFlattener::flattenedPath($src);
        $mtime1 = filemtime($out1);

        // Cache hit — druhý průchod nesmí soubor přepsat.
        clearstatcache();
        $out2 = PdfLogoFlattener::flattenedPath($src);
        self::assertSame($out1, $out2);
        self::assertSame($mtime1, filemtime($out2));

        // Re-upload (zdroj novější) → flattened se regeneruje.
        touch($src, time() + 5);
        clearstatcache();
        $out3 = PdfLogoFlattener::flattenedPath($src);
        self::assertSame($out1, $out3);
        self::assertFileExists($out3);
    }

    public function testCleanupRemovesCache(): void
    {
        $src = $this->dir . '/sup-3.png';
        $this->makeTransparentBlackPng($src);
        $out = PdfLogoFlattener::flattenedPath($src);
        self::assertFileExists($out);

        PdfLogoFlattener::cleanup($src);
        self::assertFileDoesNotExist($out);
    }

    public function testNonPngReturnedUnchanged(): void
    {
        $jpg = $this->dir . '/logo.jpg';
        file_put_contents($jpg, 'x');
        self::assertSame($jpg, PdfLogoFlattener::flattenedPath($jpg));
    }

    public function testMissingFileReturnedUnchanged(): void
    {
        $missing = $this->dir . '/nope.png';
        self::assertSame($missing, PdfLogoFlattener::flattenedPath($missing));
    }
}
