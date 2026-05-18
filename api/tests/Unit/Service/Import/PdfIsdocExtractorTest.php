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
