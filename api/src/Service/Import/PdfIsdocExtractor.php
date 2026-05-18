<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Vytahá ISDOC XML přílohu z PDF/A-3 invoice souboru.
 *
 * Vstup je raw PDF (typicky vyrobený mPDFem přes SetAssociatedFiles, ale parser
 * pracuje s libovolným PDF, který má `/Type /EmbeddedFile` objekty s FlateDecode
 * streamem). Identifikujeme ISDOC dvojkrokem:
 *   1) primárně podle filename (`*.isdoc`) v souvisejícím FileSpec dict,
 *   2) content sniffem nadekomprimovaného streamu (ISDOC namespace
 *      `http://isdoc.cz/namespace/2013` + `<Invoice`) jako záchrana, kdyby
 *      filename byl jiný nebo chyběl.
 *
 * Vrátí XML string nebo null, pokud PDF žádný ISDOC neobsahuje.
 *
 * Omezení: nepodporujeme PDF, kde jsou objekty zabalené v compressed object
 * streams (`/Type /ObjStm`). mPDF (náš generátor) je nepoužívá, většina
 * producentů PDF/A-3 invoice atřaktur taky ne. Pokud na takový PDF narazíme,
 * vrátíme null a uživatel uvidí čitelnou chybu „PDF neobsahuje ISDOC".
 */
final class PdfIsdocExtractor
{
    private const ISDOC_NS = 'http://isdoc.cz/namespace/2013';
    private const MAX_DECOMPRESSED_BYTES = 10 * 1024 * 1024; // 10 MiB, anti zip-bomb

    public function extract(string $pdfBytes): ?string
    {
        if (!str_starts_with($pdfBytes, '%PDF-')) {
            return null;
        }

        // 1) Najdi všechny EmbeddedFile objekty (raw stream bytes per objektu).
        $candidates = $this->findEmbeddedFileStreams($pdfBytes);
        if ($candidates === []) {
            return null;
        }

        // 2) Zjisti, který objekt číslo odkazuje FileSpec s filename `*.isdoc`.
        //    Vrátí seznam objektu IDs preferovaných pro ISDOC (může být víc, prvni vyhrává).
        $preferredObjIds = $this->findIsdocFileSpecRefs($pdfBytes);

        // 3) Vyzkoušej nejprve preferované (filename match), pak ostatní (content sniff).
        $ordered = [];
        foreach ($preferredObjIds as $id) {
            if (isset($candidates[$id])) {
                $ordered[$id] = $candidates[$id];
            }
        }
        foreach ($candidates as $id => $stream) {
            if (!isset($ordered[$id])) {
                $ordered[$id] = $stream;
            }
        }

        foreach ($ordered as $stream) {
            $xml = $this->tryInflate($stream);
            if ($xml === null) {
                continue;
            }
            if ($this->looksLikeIsdoc($xml)) {
                return $xml;
            }
        }

        return null;
    }

    /**
     * Vrátí mapu `objectId => raw streamBytes` pro objekty s `/Type /EmbeddedFile`.
     *
     * Strategie: hledáme přímo `/Type/EmbeddedFile` markery — vyhneme se tak
     * falešné shodě na "endobj" uvnitř binárních streamů (typický problém
     * iterace přes `\d+\s+\d+\s+obj` … `endobj`). Z markeru zpětně dohledáme
     * obj ID a dopředu stream přes `/Length`.
     *
     * @return array<int, string>
     */
    private function findEmbeddedFileStreams(string $pdf): array
    {
        $result = [];
        $offset = 0;
        $markerRe = '#/Type\s*/EmbeddedFile\b#';

        while (preg_match($markerRe, $pdf, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $markerPos = $m[0][1];

            // Zpětně dohledat nejbližší `N M obj\n` header (PDF spec: obj
            // header musí předcházet dictu/streamu).
            $objHeader = $this->findObjHeaderBefore($pdf, $markerPos);
            if ($objHeader === null) {
                $offset = $markerPos + 1;
                continue;
            }

            // Najít začátek streamu: `stream` keyword na vlastním řádku, hned
            // za dict-close `>>`. POZOR: pouhé `\bstream` matchne i `-stream`
            // v `Subtype/application#2Foctet-stream` (iDoklad PDF používá
            // application/octet-stream MIME jako Subtype). Anchorujeme proto
            // na `>>` + whitespace + `stream` + EOL.
            if (!preg_match('/>>\s*stream(\r?\n|\r)/', $pdf, $sm, PREG_OFFSET_CAPTURE, $markerPos)) {
                $offset = $markerPos + 1;
                continue;
            }
            // sm[0] zahrnuje `>>` — `sPos` má ukazovat na `stream`, ne na `>>`.
            $sPos = $sm[0][1] + strlen($sm[0][0]) - 6 - strlen($sm[1][0]);
            $afterStream = $sm[0][1] + strlen($sm[0][0]);

            // Použij `/Length N` z dictu pro přesnou velikost streamu —
            // robustnější než hledat `endstream` (binární stream by mohl
            // obsahovat literální bytes "endstream").
            $dict = substr($pdf, $objHeader['bodyStart'], $sPos - $objHeader['bodyStart']);
            $stream = null;
            if (preg_match('#/Length\s+(\d+)\b#', $dict, $lm)) {
                $length = (int) $lm[1];
                if ($length > 0 && $length <= self::MAX_DECOMPRESSED_BYTES) {
                    $stream = substr($pdf, $afterStream, $length);
                }
            }
            if ($stream === null) {
                // Fallback: hledej `endstream` (méně spolehlivé).
                $endStream = strpos($pdf, 'endstream', $afterStream);
                if ($endStream !== false) {
                    $streamEnd = $endStream;
                    while ($streamEnd > $afterStream && ($pdf[$streamEnd - 1] === "\n" || $pdf[$streamEnd - 1] === "\r")) {
                        $streamEnd--;
                    }
                    $stream = substr($pdf, $afterStream, $streamEnd - $afterStream);
                }
            }

            if ($stream !== null && $stream !== '') {
                $result[$objHeader['id']] = $stream;
            }
            $offset = $afterStream + (is_string($stream) ? strlen($stream) : 1);
        }

        return $result;
    }

    /**
     * Zpětně dohledá nejbližší `N M obj\n` header před zadanou pozicí.
     *
     * @return array{id:int, bodyStart:int}|null
     */
    private function findObjHeaderBefore(string $pdf, int $pos): ?array
    {
        // Hledáme v okně ~4 KB zpětně — obj header je typicky pár desítek bajtů
        // před `/Type/EmbeddedFile`. 4 KB je bezpečná rezerva pro dlouhé dicty.
        $windowStart = max(0, $pos - 4096);
        $window = substr($pdf, $windowStart, $pos - $windowStart);

        // Najdi POSLEDNÍ výskyt patternu `(\d+) (\d+) obj` (= nejbližší před $pos).
        if (!preg_match_all('/(\d+)\s+(\d+)\s+obj\b/', $window, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $last = end($matches[0]);
        $lastMatchOffset = $last[1];
        $lastMatchLen = strlen($last[0]);
        return [
            'id'        => (int) end($matches[1])[0],
            'bodyStart' => $windowStart + $lastMatchOffset + $lastMatchLen,
        ];
    }

    /**
     * Najde FileSpec dictionary, kde filename končí na `.isdoc`, a vrátí
     * objectId referencovaný v `/EF << /F N 0 R >>`. Pokud `/F` chybí, zkusí
     * `/UF` (unicode filename, schema-preferred dle PDF 1.7).
     *
     * @return list<int>
     */
    private function findIsdocFileSpecRefs(string $pdf): array
    {
        $ids = [];
        // FileSpec dict typicky:
        //   /F (invoice.isdoc) /UF (invoice.isdoc) /EF << /F 11 0 R >>
        // nebo (iDoklad):
        //   /UF (Vydaná faktura.isdoc) /F (Vydaná faktura.isdoc) /EF << /UF 29 0 R /F 29 0 R >>
        //
        // Strategie: pro každý filename s `.isdoc` najdi nejbližší následující
        // `/EF << ... >>` blok a vytáhni první `\d+ 0 R` referenci uvnitř.
        $fileRe = '#/(?:F|UF)\s*\(([^)]*\.isdoc)\)#i';
        if (preg_match_all($fileRe, $pdf, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $hit) {
                $pos = $hit[1];
                // Najdi následující /EF << ... >> blok do 256 B (typicky desítky B).
                $tail = substr($pdf, $pos, 512);
                if (preg_match('#/EF\s*<<([^>]+)>>#', $tail, $efMatch)) {
                    $ef = $efMatch[1];
                    if (preg_match('#(\d+)\s+\d+\s+R#', $ef, $refMatch)) {
                        $ids[] = (int) $refMatch[1];
                    }
                }
            }
        }
        // Deduplikuj při zachování pořadí.
        return array_values(array_unique($ids));
    }

    /**
     * Nadekomprimuje FlateDecode stream. Vrátí UTF-8 XML nebo null, pokud
     * dekomprese selže nebo výsledek překračuje limit.
     */
    private function tryInflate(string $stream): ?string
    {
        // PDF FlateDecode = zlib-formatted data (header 0x78 0x9C/0xDA atd.).
        // gzuncompress odpovídá tomuto formátu.
        $inflated = @gzuncompress($stream);
        if ($inflated === false) {
            // Fallback: někdy je stream už raw deflate (bez zlib hlavičky).
            $inflated = @gzinflate($stream);
            if ($inflated === false) {
                return null;
            }
        }
        if (strlen($inflated) > self::MAX_DECOMPRESSED_BYTES) {
            return null;
        }
        return $inflated;
    }

    private function looksLikeIsdoc(string $xml): bool
    {
        return str_contains($xml, self::ISDOC_NS) && str_contains($xml, '<Invoice');
    }
}
