<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Best-effort heuristika: z PDF vytáhne embedded rastrové obrázky (image XObjekty)
 * a vrátí ten, který vypadá jako QR kód — zhruba čtvercový, převážně bílý s černými
 * částmi (bilevel). Slouží jako poslední fallback pro „Zaplatit pomocí QR" u přijaté
 * faktury, když platební účet nelze získat z ISDOC ani z AI.
 *
 * NEdekóduje QR (žádná závislost na ZXing) — jen najde a zobrazí obrázek tak, aby ho
 * uživatel mohl naskenovat v bankovní aplikaci.
 *
 * Podporované kódování streamů (jinak kandidáta přeskočí — radši nic než zkreslený
 * obrázek): DCTDecode (JPEG přes GD), FlateDecode (vč. PNG/TIFF prediktoru) pro
 * DeviceGray (1/8 bpc) a DeviceRGB (8 bpc). Indexed/ICCBased/CCITT/JPX/JBIG2 = skip.
 *
 * Bezpečnost: limit počtu skenovaných objektů, limit dekomprese (anti zip-bomb),
 * limit pixelů (anti memory blow-up) — vzor PdfIsdocExtractor.
 */
final class PdfImageExtractor
{
    private const MAX_DECOMPRESSED_BYTES = 16 * 1024 * 1024; // 16 MiB
    private const MAX_OBJECTS            = 80;
    private const MAX_PIXELS             = 4_000_000;
    private const MIN_DIM                = 60;
    private const MAX_DIM                = 3000;

    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Najde nejlepšího QR-like kandidáta a vrátí data-URI (PNG), nebo null.
     */
    public function findQrLikeImage(string $pdfBytes): ?string
    {
        if (!str_starts_with($pdfBytes, '%PDF-') || !\function_exists('imagecreatetruecolor')) {
            return null;
        }

        $best = null;
        $bestScore = -INF;
        $seen = 0;

        foreach ($this->findImageXObjects($pdfBytes) as $img) {
            if (++$seen > self::MAX_OBJECTS) {
                break;
            }
            if (!$this->isSquareCandidate($img)) {
                continue;
            }
            $gd = $this->decode($img);
            if ($gd === null) {
                continue;
            }
            [$blackFrac, $bimodal] = $this->analyze($gd);
            if (!$bimodal || $blackFrac < 0.10 || $blackFrac > 0.75) {
                continue;
            }
            // Skóre: preferuj černý podíl blízko 0.45 a rozumnou velikost (100–700 px).
            $dim = max($img['width'], $img['height']);
            $sizePenalty = $dim < 100 ? (100 - $dim) / 100 : ($dim > 700 ? ($dim - 700) / 2000 : 0);
            $score = -abs($blackFrac - 0.45) - $sizePenalty;
            if ($score > $bestScore) {
                $best = $gd;
                $bestScore = $score;
            }
        }

        return $best === null ? null : $this->toPngDataUri($best);
    }

    /**
     * Najde image XObjekty: mapa metadat + raw stream. Vzor: PdfIsdocExtractor —
     * marker `/Subtype /Image`, zpětně obj header, dopředu stream přes `/Length`.
     *
     * @return list<array{width:int,height:int,bpc:int,filters:list<string>,colorspace:string,predictor:int,colors:int,pcolumns:int,pbpc:int,stream:string}>
     */
    private function findImageXObjects(string $pdf): array
    {
        $out = [];
        $offset = 0;
        $markerRe = '#/Subtype\s*/Image\b#';

        while (preg_match($markerRe, $pdf, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $markerPos = $m[0][1];
            $objHeader = $this->findObjHeaderBefore($pdf, $markerPos);
            if ($objHeader === null) {
                $offset = $markerPos + 1;
                continue;
            }
            if (!preg_match('/>>\s*stream(\r?\n|\r)/', $pdf, $sm, PREG_OFFSET_CAPTURE, $markerPos)) {
                $offset = $markerPos + 1;
                continue;
            }
            $sPos = $sm[0][1];
            $afterStream = $sm[0][1] + strlen($sm[0][0]);
            $dict = substr($pdf, $objHeader['bodyStart'], $sPos - $objHeader['bodyStart'] + 2);

            $stream = null;
            if (preg_match('#/Length\s+(\d+)\b#', $dict, $lm)) {
                $length = (int) $lm[1];
                if ($length > 0 && $length <= self::MAX_DECOMPRESSED_BYTES) {
                    $stream = substr($pdf, $afterStream, $length);
                }
            }
            if ($stream === null) {
                $endStream = strpos($pdf, 'endstream', $afterStream);
                if ($endStream !== false) {
                    $end = $endStream;
                    while ($end > $afterStream && ($pdf[$end - 1] === "\n" || $pdf[$end - 1] === "\r")) {
                        $end--;
                    }
                    $stream = substr($pdf, $afterStream, $end - $afterStream);
                }
            }

            $offset = $afterStream + (is_string($stream) ? max(1, strlen($stream)) : 1);

            if ($stream === null || $stream === '') {
                continue;
            }
            $meta = $this->parseImageMeta($dict);
            if ($meta === null) {
                continue;
            }
            $meta['stream'] = $stream;
            $out[] = $meta;
        }

        return $out;
    }

    /**
     * @return array{id:int, bodyStart:int}|null
     */
    private function findObjHeaderBefore(string $pdf, int $pos): ?array
    {
        $windowStart = max(0, $pos - 4096);
        $window = substr($pdf, $windowStart, $pos - $windowStart);
        if (!preg_match_all('/(\d+)\s+(\d+)\s+obj\b/', $window, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $last = end($matches[0]);
        return [
            'id'        => (int) end($matches[1])[0],
            'bodyStart' => $windowStart + $last[1] + strlen($last[0]),
        ];
    }

    /**
     * @return array{width:int,height:int,bpc:int,filters:list<string>,colorspace:string,predictor:int,colors:int,pcolumns:int,pbpc:int}|null
     */
    private function parseImageMeta(string $dict): ?array
    {
        if (!preg_match('#/Width\s+(\d+)#', $dict, $w) || !preg_match('#/Height\s+(\d+)#', $dict, $h)) {
            return null;
        }
        $bpc = preg_match('#/BitsPerComponent\s+(\d+)#', $dict, $bm) ? (int) $bm[1] : 8;

        $filters = [];
        if (preg_match('#/Filter\s*(/[A-Za-z0-9]+|\[[^\]]*\])#', $dict, $fm)) {
            preg_match_all('#/([A-Za-z0-9]+)#', $fm[1], $fall);
            $filters = $fall[1];
        }

        $colorspace = 'DeviceRGB';
        if (preg_match('#/ColorSpace\s*/([A-Za-z0-9]+)#', $dict, $cm)) {
            $colorspace = $cm[1];
        } elseif (preg_match('#/ColorSpace\s*\[\s*/([A-Za-z0-9]+)#', $dict, $cm2)) {
            $colorspace = $cm2[1]; // Indexed/ICCBased apod. — dekodér pak skipne
        }

        // DecodeParms predictor (PNG ≥10 / TIFF 2). Defaulty dle PDF spec.
        $predictor = 1;
        $colors = $colorspace === 'DeviceRGB' ? 3 : 1;
        $pcolumns = (int) $w[1];
        $pbpc = $bpc;
        if (preg_match('#/DecodeParms\s*<<(.+?)>>#s', $dict, $dp) || preg_match('#/DP\s*<<(.+?)>>#s', $dict, $dp)) {
            $parms = $dp[1];
            if (preg_match('#/Predictor\s+(\d+)#', $parms, $pp)) {
                $predictor = (int) $pp[1];
            }
            if (preg_match('#/Colors\s+(\d+)#', $parms, $pc)) {
                $colors = (int) $pc[1];
            }
            if (preg_match('#/Columns\s+(\d+)#', $parms, $pcol)) {
                $pcolumns = (int) $pcol[1];
            }
            if (preg_match('#/BitsPerComponent\s+(\d+)#', $parms, $pb)) {
                $pbpc = (int) $pb[1];
            }
        }

        return [
            'width'      => (int) $w[1],
            'height'     => (int) $h[1],
            'bpc'        => $bpc,
            'filters'    => $filters,
            'colorspace' => $colorspace,
            'predictor'  => $predictor,
            'colors'     => $colors,
            'pcolumns'   => $pcolumns,
            'pbpc'       => $pbpc,
        ];
    }

    /**
     * @param array{width:int,height:int} $img
     */
    private function isSquareCandidate(array $img): bool
    {
        $w = $img['width'];
        $h = $img['height'];
        if ($w < self::MIN_DIM || $h < self::MIN_DIM || $w > self::MAX_DIM || $h > self::MAX_DIM) {
            return false;
        }
        if ($w * $h > self::MAX_PIXELS) {
            return false;
        }
        $ratio = $w / $h;
        return $ratio >= 0.85 && $ratio <= 1.18;
    }

    /**
     * @param array<string,mixed> $img
     */
    private function decode(array $img): ?\GdImage
    {
        $filters = $img['filters'];
        $stream = (string) $img['stream'];

        if (in_array('DCTDecode', $filters, true)) {
            $gd = @imagecreatefromstring($stream);
            return $gd instanceof \GdImage ? $gd : null;
        }

        if (in_array('FlateDecode', $filters, true) && count($filters) <= 1) {
            $raw = @gzuncompress($stream, self::MAX_DECOMPRESSED_BYTES);
            if ($raw === false) {
                $raw = @gzinflate($stream, self::MAX_DECOMPRESSED_BYTES);
            }
            if ($raw === false) {
                return null;
            }
            return $this->rasterFromSamples($raw, $img);
        }

        return null;
    }

    /**
     * Sestaví GD obrázek z nadekomprimovaných vzorků (po případném odstranění
     * PNG/TIFF prediktoru). Podporuje DeviceGray 1/8 bpc a DeviceRGB 8 bpc.
     *
     * @param array<string,mixed> $img
     */
    private function rasterFromSamples(string $raw, array $img): ?\GdImage
    {
        $w = (int) $img['width'];
        $h = (int) $img['height'];
        $bpc = (int) $img['bpc'];
        $cs = (string) $img['colorspace'];
        $colors = $cs === 'DeviceRGB' ? 3 : ($cs === 'DeviceGray' ? 1 : 0);
        if ($colors === 0) {
            return null; // Indexed/ICCBased/CMYK… neřešíme
        }

        $rowLen = intdiv($w * $colors * $bpc + 7, 8);

        if ((int) $img['predictor'] >= 10) {
            $raw = $this->undoPngPredictor($raw, $rowLen, $colors, $bpc);
            if ($raw === null) {
                return null;
            }
        } elseif ((int) $img['predictor'] === 2) {
            return null; // TIFF predictor — vzácné, neřešíme
        }

        if (strlen($raw) < $rowLen * $h) {
            return null;
        }

        $gd = imagecreatetruecolor($w, $h);
        if (!$gd instanceof \GdImage) {
            return null;
        }

        if ($cs === 'DeviceGray' && $bpc === 1) {
            for ($y = 0; $y < $h; $y++) {
                $rowOff = $y * $rowLen;
                for ($x = 0; $x < $w; $x++) {
                    $byte = ord($raw[$rowOff + ($x >> 3)]);
                    $bit = ($byte >> (7 - ($x & 7))) & 1;
                    $v = $bit ? 255 : 0;
                    imagesetpixel($gd, $x, $y, ($v << 16) | ($v << 8) | $v);
                }
            }
        } elseif ($cs === 'DeviceGray' && $bpc === 8) {
            for ($y = 0; $y < $h; $y++) {
                $rowOff = $y * $rowLen;
                for ($x = 0; $x < $w; $x++) {
                    $v = ord($raw[$rowOff + $x]);
                    imagesetpixel($gd, $x, $y, ($v << 16) | ($v << 8) | $v);
                }
            }
        } elseif ($cs === 'DeviceRGB' && $bpc === 8) {
            for ($y = 0; $y < $h; $y++) {
                $rowOff = $y * $rowLen;
                for ($x = 0; $x < $w; $x++) {
                    $p = $rowOff + $x * 3;
                    imagesetpixel($gd, $x, $y, (ord($raw[$p]) << 16) | (ord($raw[$p + 1]) << 8) | ord($raw[$p + 2]));
                }
            }
        } else {
            return null;
        }

        return $gd;
    }

    /**
     * Odstraní PNG prediktor (filter type byte na začátku každého řádku, typy 0–4).
     * Vrací čisté vzorky (rowLen*height), nebo null při nekonzistenci.
     */
    private function undoPngPredictor(string $data, int $rowLen, int $colors, int $bpc): ?string
    {
        $bpp = max(1, intdiv($colors * $bpc, 8)); // bytes per pixel pro Sub/Avg/Paeth
        $stride = $rowLen + 1;                     // +1 filter byte per řádek
        $len = strlen($data);
        if ($len < $stride || $len % $stride !== 0) {
            // Některé enkodéry nepadují přesně — spočti počet kompletních řádků.
            $rows = intdiv($len, $stride);
            if ($rows < 1) {
                return null;
            }
        } else {
            $rows = intdiv($len, $stride);
        }

        $out = '';
        $prev = str_repeat("\x00", $rowLen);
        for ($r = 0; $r < $rows; $r++) {
            $base = $r * $stride;
            $ft = ord($data[$base]);
            $cur = substr($data, $base + 1, $rowLen);
            if (strlen($cur) < $rowLen) {
                break;
            }
            $cur = $this->undoPngRow($ft, $cur, $prev, $bpp, $rowLen);
            if ($cur === null) {
                return null;
            }
            $out .= $cur;
            $prev = $cur;
        }
        return $out !== '' ? $out : null;
    }

    private function undoPngRow(int $filter, string $cur, string $prev, int $bpp, int $rowLen): ?string
    {
        $b = array_values(unpack('C*', $cur) ?: []);
        $p = array_values(unpack('C*', $prev) ?: []);
        if (count($b) < $rowLen) {
            return null;
        }
        for ($i = 0; $i < $rowLen; $i++) {
            $a = $i >= $bpp ? $b[$i - $bpp] : 0;        // vlevo
            $up = $p[$i] ?? 0;                           // nahoře
            $ul = $i >= $bpp ? ($p[$i - $bpp] ?? 0) : 0; // vlevo nahoře
            switch ($filter) {
                case 0: break;                            // None
                case 1: $b[$i] = ($b[$i] + $a) & 0xFF; break;        // Sub
                case 2: $b[$i] = ($b[$i] + $up) & 0xFF; break;       // Up
                case 3: $b[$i] = ($b[$i] + intdiv($a + $up, 2)) & 0xFF; break; // Average
                case 4: $b[$i] = ($b[$i] + $this->paeth($a, $up, $ul)) & 0xFF; break; // Paeth
                default: return null;
            }
        }
        return pack('C*', ...$b);
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        return $pb <= $pc ? $b : $c;
    }

    /**
     * Vzorkuje obrázek v mřížce a vrátí [podíl černé, je-bimodální].
     *
     * @return array{0:float,1:bool}
     */
    private function analyze(\GdImage $gd): array
    {
        $w = imagesx($gd);
        $h = imagesy($gd);
        $stepX = max(1, intdiv($w, 80));
        $stepY = max(1, intdiv($h, 80));
        $black = 0;
        $white = 0;
        $mid = 0;
        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $rgb = imagecolorat($gd, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $bl = $rgb & 0xFF;
                $lum = (int) (0.299 * $r + 0.587 * $g + 0.114 * $bl);
                if ($lum < 96) {
                    $black++;
                } elseif ($lum > 160) {
                    $white++;
                } else {
                    $mid++;
                }
            }
        }
        $total = $black + $white + $mid;
        if ($total === 0) {
            return [0.0, false];
        }
        $bimodal = ($black + $white) / $total >= 0.92;
        $bw = $black + $white;
        $blackFrac = $bw > 0 ? $black / $bw : 0.0;
        return [$blackFrac, $bimodal];
    }

    private function toPngDataUri(\GdImage $gd): ?string
    {
        ob_start();
        $ok = imagepng($gd);
        $png = (string) ob_get_clean();
        if (!$ok || $png === '') {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode($png);
    }
}
