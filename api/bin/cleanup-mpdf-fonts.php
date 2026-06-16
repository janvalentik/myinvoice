<?php

declare(strict_types=1);

/**
 * Po composer install/update odstraní z mpdf/mpdf/ttfonts/ všechny TTF
 * soubory, které pro MyInvoice.cz nepotřebujeme (české faktury → DejaVu Sans).
 *
 * Šetří ~85 MB v repu / deploy artifactu.
 *
 * Whitelist je zde — když budeš chtít přidat font (např. pro AJ klienty
 * nebo PDF s monospace položkami), doplň ho do $keep.
 */

$ttfontsDir = __DIR__ . '/../vendor/mpdf/mpdf/ttfonts';
if (!is_dir($ttfontsDir)) {
    fwrite(STDERR, "ttfonts adresář nenalezen: {$ttfontsDir}\n");
    exit(0); // no-op (mpdf nenainstalován)
}

$keep = [
    // DejaVu Sans = jediný `backupSubsFont` pro glyfy, které Montserrat nemá
    // (✓ ✗ ⚠ ◆ …). Primární písma (Montserrat + JetBrains Mono) jsou v
    // api/resources/fonts/ (mimo tenhle adresář). Mono pasáže (varsymboly, IBANy,
    // čísla účtů) jedou přes JetBrains Mono → DejaVu Sans Mono ANI Condensed netřeba.
    // ⚠️ Pozor: tenhle whitelist MUSÍ sedět s `backupSubsFont` v MpdfFontConfig.php
    // (= ['dejavusans']). Kdyby tam přibyl 'dejavusansmono', vrať sem i DejaVuSansMono*.
    'DejaVuSans.ttf',
    'DejaVuSans-Bold.ttf',
    'DejaVuSans-Oblique.ttf',
    'DejaVuSans-BoldOblique.ttf',
];

$deleted = 0;
$keptCount = 0;
$bytesFreed = 0;

$files = glob($ttfontsDir . '/*.ttf') ?: [];
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $keep, true)) {
        $keptCount++;
        continue;
    }
    $size = filesize($file) ?: 0;
    if (@unlink($file)) {
        $deleted++;
        $bytesFreed += $size;
    }
}

// Smaž i .otf, .pfb a další ne-TTF font formáty (mpdf je nepoužívá pro DejaVu)
foreach (['*.otf', '*.pfb', '*.pfm', '*.afm'] as $pattern) {
    foreach (glob($ttfontsDir . '/' . $pattern) ?: [] as $file) {
        $size = filesize($file) ?: 0;
        if (@unlink($file)) {
            $deleted++;
            $bytesFreed += $size;
        }
    }
}

printf(
    "[mpdf-fonts-cleanup] Smazáno %d souborů (%.1f MB), ponecháno %d.\n",
    $deleted,
    $bytesFreed / 1048576,
    $keptCount,
);
