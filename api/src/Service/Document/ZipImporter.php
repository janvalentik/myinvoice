<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

/**
 * Bezpečné rozbalení ZIP archivu na jednotlivé entries pro režim „rozbalit a
 * kategorizovat". Tvrdé limity proti zip-bombě + ochrana proti Zip Slip.
 *
 * Vnořené archivy (.zip uvnitř .zip) NEROZBALUJEME rekurzivně — vrátí se jako
 * jeden soubor (prevence amplifikace). Adresářová struktura se zachová jako
 * segmenty cesty, ze kterých volající rekonstruuje strom složek.
 */
final class ZipImporter
{
    private const MAX_ENTRIES            = 5_000;
    private const MAX_TOTAL_UNCOMPRESSED = 300 * 1024 * 1024;
    private const RATIO_LIMIT            = 200;   // uncompressed/compressed
    private const RATIO_MIN_BYTES        = 1_048_576;

    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * @return list<array{segments:list<string>,name:string,bytes:string}>
     * @throws DocumentException
     */
    public function extractEntries(string $zipPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new DocumentException('zip_unsupported', 'Rozbalení ZIP není na serveru dostupné (ext-zip chybí).', 500);
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
            // CHECKCONS může selhat u jinak validních archivů — zkus bez něj.
            if ($zip->open($zipPath) !== true) {
                throw new DocumentException('zip_invalid', 'ZIP archiv se nepodařilo otevřít.', 422);
            }
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            throw new DocumentException('zip_too_many', 'ZIP obsahuje příliš mnoho souborů (max ' . self::MAX_ENTRIES . ').', 413);
        }

        $maxPerEntry = $this->storage->maxFileBytes();
        $entries = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) continue;
            // Názvy: Windows ZIP často ukládá diakritiku v OEM kódování (CP852)
            // bez UTF-8 příznaku → mojibake. Dekódujeme z raw bytů.
            $rawName = $zip->getNameIndex($i, \ZipArchive::FL_ENC_RAW);
            $name = $this->decodeEntryName($rawName !== false ? (string) $rawName : (string) $stat['name']);

            // Adresářové entries přeskoč (strom rekonstruujeme z cest souborů).
            if ($name === '' || str_ends_with($name, '/')) continue;

            // Zip Slip / absolutní cesty / traversal
            if ($this->isUnsafePath($name)) {
                $zip->close();
                throw new DocumentException('zip_unsafe_path', 'ZIP obsahuje nebezpečnou cestu: ' . $name, 422);
            }

            $uncompressed = (int) ($stat['size'] ?? 0);
            $compressed   = (int) ($stat['comp_size'] ?? 0);

            if ($uncompressed > $maxPerEntry) {
                $zip->close();
                throw new DocumentException('zip_entry_too_large',
                    'Soubor v ZIPu je příliš velký: ' . $name, 413);
            }
            // Anti zip-bomb: extrémní kompresní poměr
            if ($compressed > 0 && $uncompressed > self::RATIO_MIN_BYTES
                && ($uncompressed / $compressed) > self::RATIO_LIMIT) {
                $zip->close();
                throw new DocumentException('zip_bomb', 'ZIP vypadá jako dekompresní bomba.', 422);
            }
            $total += $uncompressed;
            if ($total > self::MAX_TOTAL_UNCOMPRESSED) {
                $zip->close();
                throw new DocumentException('zip_total_too_large', 'Rozbalený obsah ZIPu je příliš velký.', 413);
            }

            // Stream čtení s tvrdým stropem (nedůvěřuj statu — guard i tady).
            $bytes = $this->readEntry($zip, $i, $maxPerEntry);
            if ($bytes === null) continue;

            $rawSegments = explode('/', str_replace('\\', '/', $name));
            $fileName = (string) array_pop($rawSegments);
            $segments = [];
            foreach ($rawSegments as $seg) {
                $seg = $this->storage->sanitizeFilename($seg);
                if ($seg === '' || $seg === '.' || $seg === '..') continue;
                $segments[] = $seg;
            }
            $entries[] = ['segments' => $segments, 'name' => $fileName, 'bytes' => $bytes];
        }
        $zip->close();
        return $entries;
    }

    private function readEntry(\ZipArchive $zip, int $index, int $cap): ?string
    {
        $fh = $zip->getStreamIndex($index);
        if ($fh === false) {
            // Fallback (starší API) — getFromIndex s délkovým stropem.
            $data = $zip->getFromIndex($index, $cap + 1);
            if ($data === false) return null;
            if (strlen($data) > $cap) {
                throw new DocumentException('zip_entry_too_large', 'Soubor v ZIPu přesáhl limit.', 413);
            }
            return $data;
        }
        $data = '';
        while (!feof($fh)) {
            $chunk = fread($fh, 65536);
            if ($chunk === false) break;
            $data .= $chunk;
            if (strlen($data) > $cap) {
                fclose($fh);
                throw new DocumentException('zip_entry_too_large', 'Soubor v ZIPu přesáhl limit.', 413);
            }
        }
        fclose($fh);
        return $data;
    }

    /**
     * Dekóduje název entry ze ZIPu na UTF-8. Pokud raw byty nejsou validní UTF-8
     * (typicky Windows ZIP bez UTF-8 příznaku), zkusí CP852 (DOS Czech/OEM) a poté
     * Windows-1250. CP852 je default pro „Send to → Compressed folder" v CZ locale.
     */
    private function decodeEntryName(string $raw): string
    {
        if ($raw === '' || mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        if (function_exists('iconv')) {
            foreach (['CP852', 'Windows-1250', 'ISO-8859-2'] as $enc) {
                $conv = @iconv($enc, 'UTF-8//IGNORE', $raw);
                if ($conv !== false && $conv !== '' && mb_check_encoding($conv, 'UTF-8')) {
                    return $conv;
                }
            }
        }
        // Poslední záchrana: zahoď neplatné byty.
        return (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    }

    private function isUnsafePath(string $name): bool
    {
        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) return true;
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $name) === 1) return true; // C:\ …
        foreach (explode('/', str_replace('\\', '/', $name)) as $seg) {
            if ($seg === '..') return true;
        }
        return false;
    }
}
