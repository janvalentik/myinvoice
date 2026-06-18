<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Workaround pro mPDF bug s průhlednými truecolor PNG (issue #152).
 *
 * mPDF 8.3.1 (na PHP 8.5) neaplikuje SMask u truecolor RGBA PNG (PNG color type 6):
 * alfa kanál zahodí a do PDF vykreslí holé RGB, které leží *pod* průhlednými pixely.
 * Grafické editory tam typicky ukládají černou (0,0,0,0) → logo s průhledným pozadím
 * se v PDF zobrazí s **černým** pozadím. (Palette PNG s tRNS i SVG renderuje mPDF
 * správně — láme se jen truecolor s alfa kanálem.)
 *
 * Řešení: pro PDF logo splácneme alfa kanál na bílé pozadí (composite přes bílé
 * plátno, výstup bez alfa = PNG color type 2, takže mPDF rozbitý SMask vůbec nepoužije).
 * Faktura i výkaz víceprací mají bílé pozadí, takže výsledek je vizuálně shodný
 * s průhledným logem. E-mail dál používá průhledné PNG (v HTML e-mailu alfa funguje).
 *
 * Výsledek se cachuje do sourozence `*.pdf.png` a je self-healing — regeneruje se,
 * jakmile je zdrojové PNG novější (tj. po re-uploadu loga). Žádná migrace není třeba.
 */
final class PdfLogoFlattener
{
    private const SUFFIX = '.pdf.png';

    /**
     * Vrátí cestu k bíle-podloženému PNG vhodnému pro mPDF. Při jakékoli chybě
     * vrací $pngAbsPath beze změny (degradace na původní chování, ne pád renderu).
     */
    public static function flattenedPath(string $pngAbsPath): string
    {
        if (!extension_loaded('gd') || !is_file($pngAbsPath)) {
            return $pngAbsPath;
        }
        // Alfa problém má jen PNG; ostatní formáty nech být.
        if (!preg_match('/\.png$/i', $pngAbsPath)) {
            return $pngAbsPath;
        }

        $flat = self::flattenedSibling($pngAbsPath);

        // Cache hit — flattened existuje a není starší než zdroj.
        if (is_file($flat) && (int) @filemtime($flat) >= (int) @filemtime($pngAbsPath)) {
            return $flat;
        }

        return self::generate($pngAbsPath, $flat) ? $flat : $pngAbsPath;
    }

    /** Smaže cache flattened loga (idempotentní). Volá SupplierLogoConverter. */
    public static function cleanup(string $pngAbsPath): void
    {
        $flat = self::flattenedSibling($pngAbsPath);
        if (is_file($flat)) {
            @unlink($flat);
        }
    }

    private static function flattenedSibling(string $pngAbsPath): string
    {
        return (string) preg_replace('/\.png$/i', self::SUFFIX, $pngAbsPath);
    }

    private static function generate(string $src, string $dst): bool
    {
        $im = @imagecreatefrompng($src);
        if (!$im) {
            return false;
        }
        $w = imagesx($im);
        $h = imagesy($im);

        $canvas = imagecreatetruecolor($w, $h);
        // Bílé plátno + zapnutý alpha blending → příchozí alfa se smíchá s bílou.
        imagealphablending($canvas, true);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
        imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);

        // Výstup BEZ alfa kanálu (color type 2) → mPDF nezkouší rozbitý SMask.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, false);
        $ok = @imagepng($canvas, $dst, 6);

        return $ok && is_file($dst);
    }
}
