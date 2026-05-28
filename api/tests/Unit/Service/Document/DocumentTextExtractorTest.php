<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document;

use MyInvoice\Service\Document\DocumentTextExtractor;
use PHPUnit\Framework\TestCase;

final class DocumentTextExtractorTest extends TestCase
{
    private string $tmp;

    protected function tearDown(): void
    {
        if (isset($this->tmp) && is_file($this->tmp)) @unlink($this->tmp);
    }

    private function file(string $content, string $ext): string
    {
        $this->tmp = (string) tempnam(sys_get_temp_dir(), 'txt') . '.' . $ext;
        file_put_contents($this->tmp, $content);
        return $this->tmp;
    }

    public function testPlainText(): void
    {
        $res = (new DocumentTextExtractor())->extract($this->file('Smlouva o dílo č. 42', 'txt'), 'other', 'txt');
        self::assertSame('extracted', $res['status']);
        self::assertStringContainsString('Smlouva o dílo', (string) $res['text']);
    }

    public function testXmlStripsTags(): void
    {
        $xml = '<root><name>ACME</name><note>Faktura 2026</note></root>';
        $res = (new DocumentTextExtractor())->extract($this->file($xml, 'xml'), 'xml', 'xml');
        self::assertSame('extracted', $res['status']);
        self::assertStringContainsString('ACME', (string) $res['text']);
        self::assertStringContainsString('Faktura 2026', (string) $res['text']);
        self::assertStringNotContainsString('<root>', (string) $res['text']);
    }

    public function testCsv(): void
    {
        $res = (new DocumentTextExtractor())->extract($this->file("a;b;c\n1;2;Praha", 'csv'), 'other', 'csv');
        self::assertSame('extracted', $res['status']);
        self::assertStringContainsString('Praha', (string) $res['text']);
    }

    public function testEmptyFileUnsupported(): void
    {
        $res = (new DocumentTextExtractor())->extract($this->file('   ', 'txt'), 'other', 'txt');
        self::assertSame('unsupported', $res['status']);
        self::assertNull($res['text']);
    }

    public function testUnknownTypeUnsupported(): void
    {
        $res = (new DocumentTextExtractor())->extract($this->file('binary', 'bin'), 'other', 'bin');
        self::assertSame('unsupported', $res['status']);
    }

    public function testNormalizesWhitespace(): void
    {
        $res = (new DocumentTextExtractor())->extract($this->file("a\t\t  b\n\n\nc", 'txt'), 'other', 'txt');
        self::assertSame('a b c', $res['text']);
    }
}
