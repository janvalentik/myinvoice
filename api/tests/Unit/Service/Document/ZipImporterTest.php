<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Document\ZipImporter;
use PHPUnit\Framework\TestCase;

final class ZipImporterTest extends TestCase
{
    private string $tmpZip;

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip není dostupné');
        }
        $this->tmpZip = (string) tempnam(sys_get_temp_dir(), 'ziptest') . '.zip';
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpZip) && is_file($this->tmpZip)) @unlink($this->tmpZip);
    }

    private function importer(int $maxBytes = 50 * 1024 * 1024): ZipImporter
    {
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturnCallback(
            static fn(string $key, mixed $default = null) => $key === 'documents.max_file_bytes' ? $maxBytes : $default
        );
        return new ZipImporter(new DocumentStorage($config));
    }

    /** @param array<string,string> $entries name => content */
    private function makeZip(array $entries): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    public function testExtractsFilesAndReconstructsFolders(): void
    {
        $this->makeZip([
            'readme.txt'        => 'hello',
            'sub/a.txt'         => 'aaa',
            'sub/deep/b.txt'    => 'bbb',
        ]);
        $entries = $this->importer()->extractEntries($this->tmpZip);

        // Indexuj podle názvu souboru pro stabilní asserty.
        $byName = [];
        foreach ($entries as $e) $byName[$e['name']] = $e;

        self::assertCount(3, $entries);
        self::assertSame([], $byName['readme.txt']['segments']);
        self::assertSame('hello', $byName['readme.txt']['bytes']);
        self::assertSame(['sub'], $byName['a.txt']['segments']);
        self::assertSame(['sub', 'deep'], $byName['b.txt']['segments']);
        self::assertSame('bbb', $byName['b.txt']['bytes']);
    }

    public function testSkipsDirectoryEntries(): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addEmptyDir('emptyfolder');
        $zip->addFromString('emptyfolder/file.txt', 'x');
        $zip->close();

        $entries = $this->importer()->extractEntries($this->tmpZip);
        self::assertCount(1, $entries);
        self::assertSame('file.txt', $entries[0]['name']);
        self::assertSame(['emptyfolder'], $entries[0]['segments']);
    }

    public function testRejectsZipSlipPath(): void
    {
        $this->makeZip(['../escape.txt' => 'evil']);
        $this->expectException(DocumentException::class);
        $this->expectExceptionMessageMatches('/nebezpe/i');
        $this->importer()->extractEntries($this->tmpZip);
    }

    public function testRejectsEntryOverSizeLimit(): void
    {
        // limit 16 B, entry 1 KB → musí selhat
        $this->makeZip(['big.txt' => str_repeat('A', 1024)]);
        $this->expectException(DocumentException::class);
        $this->importer(16)->extractEntries($this->tmpZip);
    }

    public function testNestedZipIsKeptNotRecursed(): void
    {
        // Vnořený .zip se vrátí jako jeden soubor (nerozbalujeme rekurzivně).
        $inner = (string) tempnam(sys_get_temp_dir(), 'inner') . '.zip';
        $iz = new \ZipArchive();
        $iz->open($inner, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $iz->addFromString('x.txt', 'y');
        $iz->close();

        $this->makeZip(['nested.zip' => (string) file_get_contents($inner)]);
        @unlink($inner);

        $entries = $this->importer()->extractEntries($this->tmpZip);
        self::assertCount(1, $entries);
        self::assertSame('nested.zip', $entries[0]['name']);
    }
}
