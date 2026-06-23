<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\DocumentStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentStorageTest extends TestCase
{
    private function storage(int $maxBytes = 50 * 1024 * 1024): DocumentStorage
    {
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key, mixed $default = null) => $key === 'documents.max_file_bytes' ? $maxBytes : $default
        );
        return new DocumentStorage($config);
    }

    // ───────── sanitizeFilename ─────────

    /** @return array<string,array{string,string}> */
    public static function filenameProvider(): array
    {
        return [
            'path traversal'   => ['../../etc/passwd', 'passwd'],
            'absolute path'    => ['/var/www/secret.pdf', 'secret.pdf'],
            'leading dots'     => ['...hidden.txt', 'hidden.txt'],
            'forward slashes'  => ['a/b/c.txt', 'c.txt'],
            'reserved chars'   => ['in<voi>ce:*?.pdf', 'in_voi_ce___.pdf'],
            'normal'           => ['Smlouva 2026.docx', 'Smlouva 2026.docx'],
        ];
    }

    #[DataProvider('filenameProvider')]
    public function testSanitizeFilename(string $input, string $expected): void
    {
        self::assertSame($expected, $this->storage()->sanitizeFilename($input));
    }

    public function testSanitizeStripsControlChars(): void
    {
        $out = $this->storage()->sanitizeFilename("evil\x00\x1f.txt");
        self::assertStringNotContainsString("\x00", $out);
        self::assertStringContainsString('.txt', $out);
    }

    public function testSanitizeEmptyFallsBackToDocument(): void
    {
        self::assertSame('document', $this->storage()->sanitizeFilename('...'));
        self::assertSame('document', $this->storage()->sanitizeFilename(''));
    }

    public function testSanitizeLongNameTruncated(): void
    {
        $name = str_repeat('a', 300) . '.pdf';
        $out = $this->storage()->sanitizeFilename($name);
        self::assertLessThanOrEqual(205, strlen($out));
        self::assertStringEndsWith('.pdf', $out);
    }

    // ───────── classify ─────────

    public function testClassifyAllowedTypes(): void
    {
        $s = $this->storage();
        self::assertSame('pdf',  $s->classify('pdf', 'application/pdf'));
        self::assertSame('zfo',  $s->classify('zfo', 'application/octet-stream'));
        self::assertSame('p7s',  $s->classify('p7s', 'application/pkcs7-signature'));
        self::assertSame('docx', $s->classify('docx', 'application/octet-stream'));
        self::assertSame('xlsx', $s->classify('xlsx', 'application/zip'));
        self::assertSame('xml',  $s->classify('isdoc', 'text/xml'));
        self::assertSame('image', $s->classify('png', 'image/png'));
    }

    public function testClassifyRejectsDangerousMimeEvenWithSafeExt(): void
    {
        $this->expectException(DocumentException::class);
        // .pdf přípona, ale obsah je HTML → stored-XSS risk → odmítnout
        $this->storage()->classify('pdf', 'text/html');
    }

    public function testClassifyRejectsExecutable(): void
    {
        $this->expectException(DocumentException::class);
        $this->storage()->classify('exe', 'application/x-dosexec');
    }

    public function testClassifyRejectsSvg(): void
    {
        $this->expectException(DocumentException::class);
        $this->storage()->classify('svg', 'image/svg+xml');
    }

    public function testClassifyUnknownExtensionBecomesOther(): void
    {
        // Blacklist přístup: neznámá, ale neškodná přípona projde jako 'other'
        // (např. bankovní výpisy .gpc/.abo, .json, .log…).
        $s = $this->storage();
        self::assertSame('other', $s->classify('xyz', 'application/octet-stream'));
        self::assertSame('other', $s->classify('gpc', 'text/plain'));
        self::assertSame('other', $s->classify('abo', 'text/plain'));
    }

    public function testClassifyRejectsExecutableByExtensionAlone(): void
    {
        // I s neškodným detekovaným MIME musí spustitelná přípona spadnout (blocklist přípon).
        $s = $this->storage();
        try {
            $s->classify('exe', 'application/octet-stream');
            self::fail('exe měl být odmítnut');
        } catch (DocumentException $e) {
            self::assertSame('executable_blocked', $e->errorCode);
        }
        $this->expectException(DocumentException::class);
        $s->classify('bat', 'text/plain');
    }

    public function testClassifyRejectsPhp(): void
    {
        $this->expectException(DocumentException::class);
        $this->storage()->classify('php', 'text/x-php');
    }

    // ───────── maxFileBytes ─────────

    public function testMaxFileBytesFromConfig(): void
    {
        self::assertSame(12345, $this->storage(12345)->maxFileBytes());
    }

    public function testMaxFileBytesCappedAtAbsolute(): void
    {
        // 2 GB požadavek se zaklopí na absolutní strop (500 MiB).
        self::assertLessThanOrEqual(500 * 1024 * 1024, $this->storage(2 * 1024 * 1024 * 1024)->maxFileBytes());
    }
}
