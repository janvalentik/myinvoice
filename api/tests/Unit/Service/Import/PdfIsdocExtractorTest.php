<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PdfIsdocExtractor;
use PHPUnit\Framework\TestCase;

final class PdfIsdocExtractorTest extends TestCase
{
    private PdfIsdocExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PdfIsdocExtractor();
    }

    public function testReturnsNullForNonPdfInput(): void
    {
        self::assertNull($this->extractor->extract('<not a pdf>'));
        self::assertNull($this->extractor->extract(''));
    }

    public function testReturnsNullForPdfWithoutEmbeddedFile(): void
    {
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
        self::assertNull($this->extractor->extract($pdf));
    }

    public function testExtractsIsdocFromMinimalPdfWithEmbeddedFile(): void
    {
        $isdocXml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2">'
            . '<ID>TEST-001</ID></Invoice>';
        $pdf = $this->buildMinimalPdfWithIsdoc($isdocXml, 'invoice.isdoc');

        $result = $this->extractor->extract($pdf);
        self::assertNotNull($result);
        self::assertStringContainsString('http://isdoc.cz/namespace/2013', $result);
        self::assertStringContainsString('<ID>TEST-001</ID>', $result);
    }

    public function testFindsIsdocByNonStandardFilename(): void
    {
        // Některé systémy (iDoklad) používají filename jako
        // `Vydaná faktura - 20230005-invoice.isdoc` místo `invoice.isdoc`.
        // Parser musí najít podle `.isdoc` přípony, ne podle přesného názvu.
        $isdocXml = '<?xml version="1.0"?>'
            . '<Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>CUSTOM-1</ID></Invoice>';
        $pdf = $this->buildMinimalPdfWithIsdoc($isdocXml, 'Vydana faktura-20230005-invoice.isdoc');

        $result = $this->extractor->extract($pdf);
        self::assertNotNull($result);
        self::assertStringContainsString('<ID>CUSTOM-1</ID>', $result);
    }

    public function testFindsIsdocByContentSniffWhenFilenameMissing(): void
    {
        // Když FileSpec neobsahuje `.isdoc` filename (nebo není vůbec), parser
        // musí prozkoumat všechny EmbeddedFile streamy a vybrat ten, který
        // obsahuje ISDOC namespace.
        $isdocXml = '<?xml version="1.0"?>'
            . '<Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>SNIFFED</ID></Invoice>';
        $pdf = $this->buildMinimalPdfWithIsdoc($isdocXml, 'data.bin');

        $result = $this->extractor->extract($pdf);
        self::assertNotNull($result);
        self::assertStringContainsString('<ID>SNIFFED</ID>', $result);
    }

    public function testIgnoresEmbeddedFileWithoutIsdocContent(): void
    {
        // PDF s embedded file, který NENÍ ISDOC (např. něčí logo, JSON metadata).
        $notIsdoc = '<?xml version="1.0"?><SomethingElse/>';
        $pdf = $this->buildMinimalPdfWithIsdoc($notIsdoc, 'logo.svg');

        self::assertNull($this->extractor->extract($pdf));
    }

    public function testRejectsZipBombFlateDecodeStream(): void
    {
        // SECURITY: PDF s extrémně-redundant FlateDecode streamem, který by se
        // bez `max_length` rozbalil na >10 MiB. gzuncompress se zastaví na
        // MAX_DECOMPRESSED_BYTES (10 MiB), takže nedojde k OOM a extract()
        // vrátí null (žádný platný ISDOC).
        // 20 MiB nuly se zkomprimují na cca 20 KiB — perfektní zip-bomb test.
        $bomb = str_repeat("\0", 20 * 1024 * 1024);
        $compressed = gzcompress($bomb);
        self::assertLessThan(100 * 1024, strlen($compressed), 'kompresní poměr musí být velmi vysoký pro relevantní test');
        $length = strlen($compressed);

        $pdf = "%PDF-1.7\n"
            . "1 0 obj\n<< /Type /Filespec /F (bomb.isdoc) /UF (bomb.isdoc) /EF << /F 2 0 R >> >>\nendobj\n"
            . "2 0 obj\n<< /Type /EmbeddedFile /Filter /FlateDecode /Length $length >>\nstream\n"
            . $compressed
            . "\nendstream\nendobj\n"
            . "trailer << /Root 1 0 R >>\n%%EOF\n";

        // Volání musí proběhnout v rozumném čase a paměti (ne hodit OutOfMemoryError).
        $startMem = memory_get_usage();
        $result = $this->extractor->extract($pdf);
        $endMem = memory_get_usage();

        self::assertNull($result, 'zip-bomb stream nesmí projít');
        // Defense in depth: ověř, že jsme nealokovali plných 20 MiB při extrakci.
        self::assertLessThan(15 * 1024 * 1024, $endMem - $startMem, 'extrakce nesmí alokovat dekomprimovaný obsah');
    }

    public function testHandlesOctetStreamSubtypeWithoutFalseStreamMatch(): void
    {
        // Regression: iDoklad dává EmbeddedFile Subtype `application/octet-stream`,
        // kde slovo "stream" je uvnitř MIME hodnoty (po escape #2F). Naivní
        // `\bstream` regex by matchnul tam místo na skutečný stream keyword.
        $isdocXml = '<?xml version="1.0"?>'
            . '<Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>OCTET-1</ID></Invoice>';
        $compressed = gzcompress($isdocXml);
        $length = strlen($compressed);

        // Custom PDF s octet-stream Subtype.
        $pdf = "%PDF-1.7\n"
            . "1 0 obj\n<< /Type /Filespec /F (invoice.isdoc) /UF (invoice.isdoc) "
            . "/EF << /F 2 0 R >> >>\nendobj\n"
            . "2 0 obj\n<<\n/Type/EmbeddedFile\n/Filter/FlateDecode\n"
            . "/Subtype/application#2Foctet-stream\n"
            . "/Length $length\n>>\nstream\n"
            . $compressed
            . "\nendstream\nendobj\n"
            . "trailer << /Root 1 0 R >>\n%%EOF\n";

        $result = $this->extractor->extract($pdf);
        self::assertNotNull($result, 'octet-stream Subtype should not break stream-keyword detection');
        self::assertStringContainsString('<ID>OCTET-1</ID>', $result);
    }

    public function testExtractsIsdocFromEmbeddedIsdocxPackage(): void
    {
        // Issue #136 — příloha PDF/A-3 je `.isdocx` (ZIP balíček s manifest + .isdoc + PDF),
        // ne holé .isdoc XML. Extractor musí ZIP rozbalit a vrátit vnitřní ISDOC.
        $isdocXml = '<?xml version="1.0"?>'
            . '<Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>ISDOCX-1</ID></Invoice>';
        $isdocxZip = $this->buildIsdocxZip($isdocXml);
        $pdf = $this->buildMinimalPdfWithIsdoc($isdocxZip, 'Vendor 12345678.isdocx');

        $result = $this->extractor->extract($pdf);
        self::assertNotNull($result);
        self::assertStringContainsString('http://isdoc.cz/namespace/2013', $result);
        self::assertStringContainsString('<ID>ISDOCX-1</ID>', $result);
    }

    /**
     * Sestaví ISDOCX balíček (ZIP s manifest.xml + .isdoc + PDF) pro embedded test.
     */
    private function buildIsdocxZip(string $isdocXml): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'isdocx-fixture-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.xml', '<manifest xmlns="http://isdoc.cz/namespace/2013/manifest">'
            . '<maindocument filename="vendor.isdoc"/></manifest>');
        $zip->addFromString('vendor.isdoc', $isdocXml);
        $zip->addFromString('vendor.pdf', "%PDF-1.4\nhuman readable\n%%EOF");
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /**
     * Vytvoří minimální PDF skeleton s ISDOC XML jako PDF/A-3 attachment.
     * Není to plně validní PDF (chybí xref, validní catalog), ale obsahuje
     * vše, co náš extractor potřebuje: FileSpec dict s `.isdoc` filename
     * + EmbeddedFile objekt se zlib-compressed streamem.
     */
    private function buildMinimalPdfWithIsdoc(string $isdocXml, string $filename): string
    {
        $compressed = gzcompress($isdocXml);
        $length = strlen($compressed);

        return "%PDF-1.7\n"
            . "1 0 obj\n"
            . "<< /Type /Filespec /F ($filename) /UF ($filename) "
            . "/AFRelationship /Source /EF << /F 2 0 R >> >>\n"
            . "endobj\n"
            . "2 0 obj\n"
            . "<< /Type /EmbeddedFile /Filter /FlateDecode /Length $length >>\n"
            . "stream\n"
            . $compressed
            . "\nendstream\n"
            . "endobj\n"
            . "trailer << /Root 1 0 R >>\n"
            . "%%EOF\n";
    }
}
