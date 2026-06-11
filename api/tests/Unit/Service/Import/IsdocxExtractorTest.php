<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IsdocxExtractor;
use PHPUnit\Framework\TestCase;

final class IsdocxExtractorTest extends TestCase
{
    private IsdocxExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new IsdocxExtractor();
    }

    private const ISDOC = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2"><ID>PKG-1</ID></Invoice>';

    public function testIsZipDetectsMagicBytes(): void
    {
        self::assertTrue(IsdocxExtractor::isZip("PK\x03\x04rest"));
        self::assertFalse(IsdocxExtractor::isZip('%PDF-1.7'));
        self::assertFalse(IsdocxExtractor::isZip(''));
    }

    public function testUnwrapsViaManifestMaindocument(): void
    {
        $zip = $this->buildZip([
            'manifest.xml' => '<?xml version="1.0"?><manifest xmlns="http://isdoc.cz/namespace/2013/manifest">'
                . '<maindocument filename="vendor.isdoc"/></manifest>',
            'vendor.isdoc' => self::ISDOC,
            'vendor.pdf'   => "%PDF-1.4\nhuman readable invoice\n%%EOF",
        ]);

        $out = $this->extractor->unwrap($zip);
        self::assertNotNull($out);
        self::assertStringContainsString('<ID>PKG-1</ID>', $out['isdoc']);
        self::assertSame('vendor.isdoc', $out['isdoc_name']);
        self::assertNotNull($out['pdf']);
        self::assertStringStartsWith('%PDF', (string) $out['pdf']);
        self::assertSame('vendor.pdf', $out['pdf_name']);
    }

    public function testFallsBackToRootIsdocWhenManifestMissing(): void
    {
        // ISDOC ≤5.x balíčky manifest neměly — bere se .isdoc v rootu archivu.
        $zip = $this->buildZip([
            'invoice.isdoc' => self::ISDOC,
            'invoice.pdf'   => "%PDF-1.4\nx\n%%EOF",
        ]);

        $out = $this->extractor->unwrap($zip);
        self::assertNotNull($out);
        self::assertSame('invoice.isdoc', $out['isdoc_name']);
        self::assertStringContainsString('<ID>PKG-1</ID>', $out['isdoc']);
    }

    public function testManifestPointingToMissingFileFallsBackToRootIsdoc(): void
    {
        $zip = $this->buildZip([
            'manifest.xml' => '<manifest xmlns="http://isdoc.cz/namespace/2013/manifest">'
                . '<maindocument filename="ghost.isdoc"/></manifest>',
            'real.isdoc'   => self::ISDOC,
        ]);

        $out = $this->extractor->unwrap($zip);
        self::assertNotNull($out);
        self::assertSame('real.isdoc', $out['isdoc_name']);
    }

    public function testReturnsNullForNonZip(): void
    {
        self::assertNull($this->extractor->unwrap('%PDF-1.7 not a zip'));
        self::assertNull($this->extractor->unwrap('plain text'));
        self::assertNull($this->extractor->unwrap(''));
    }

    public function testReturnsNullForZipWithoutIsdocMember(): void
    {
        $zip = $this->buildZip([
            'readme.txt'  => 'hello',
            'invoice.pdf' => "%PDF-1.4\nx\n%%EOF",
        ]);
        self::assertNull($this->extractor->unwrap($zip));
    }

    public function testReturnsNullWhenIsdocMemberLacksNamespace(): void
    {
        // Soubor s příponou .isdoc, ale bez ISDOC namespace → nedůvěřujeme.
        $zip = $this->buildZip(['fake.isdoc' => '<NotIsdoc/>']);
        self::assertNull($this->extractor->unwrap($zip));
    }

    public function testPdfMemberWithoutPdfHeaderIsIgnored(): void
    {
        // .pdf člen, který reálně není PDF → nearchivujeme ho (pdf = null),
        // ale ISDOC se i tak vrátí.
        $zip = $this->buildZip([
            'invoice.isdoc' => self::ISDOC,
            'invoice.pdf'   => 'this is not really a pdf',
        ]);
        $out = $this->extractor->unwrap($zip);
        self::assertNotNull($out);
        self::assertNull($out['pdf']);
        self::assertNull($out['pdf_name']);
    }

    public function testPrefersRootIsdocOverNestedAttachment(): void
    {
        $zip = $this->buildZip([
            'attachments/extra.isdoc' => '<Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>NESTED</ID></Invoice>',
            'main.isdoc'              => self::ISDOC,
        ]);
        $out = $this->extractor->unwrap($zip);
        self::assertNotNull($out);
        self::assertSame('main.isdoc', $out['isdoc_name']);
        self::assertStringContainsString('<ID>PKG-1</ID>', $out['isdoc']);
    }

    public function testZipBombMemberGuardReturnsNull(): void
    {
        // Vnitřní .isdoc nad 20 MiB se nečte (zip-bomb guard) → unwrap vrátí null.
        $huge = '<Invoice xmlns="http://isdoc.cz/namespace/2013">' . str_repeat('A', 21 * 1024 * 1024) . '</Invoice>';
        $zip = $this->buildZip(['invoice.isdoc' => $huge]);

        $startMem = memory_get_usage();
        $out = $this->extractor->unwrap($zip);
        $endMem = memory_get_usage();

        self::assertNull($out);
        self::assertLessThan(15 * 1024 * 1024, $endMem - $startMem, 'guard nesmí alokovat celý dekomprimovaný člen');
    }

    /**
     * @param array<string,string> $files name => content
     */
    private function buildZip(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'isdocxtest-');
        self::assertNotFalse($tmp);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp, \ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
}
