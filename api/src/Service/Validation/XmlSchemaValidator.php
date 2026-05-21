<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

use MyInvoice\Bootstrap;

/**
 * XSD validation pro EPO XML výkazy MFČR.
 *
 * **Strategie:** XSD schémata MFČR jsou veřejně dostupné na adisspr.mfcr.cz, ale
 * vyžadují stažení (různé verze, ~10 souborů). Aplikace operuje "offline" — pokud
 * je schema přítomen v `storage/xsd/{form_code}.xsd`, validujeme. Jinak `skipped`.
 *
 * Setup (volitelný — pro plnou compliance):
 *   1. Stáhni XSD soubory z https://adisspr.mfcr.cz/dpr/adis/idpr_pub/dpr_info/xsd.faces
 *   2. Uložit do `storage/xsd/`:
 *        - dphdp3.xsd  (DPH přiznání DPHDP3)
 *        - dphkh1.xsd  (Kontrolní hlášení DPHKH1)
 *        - dphshv.xsd  (Souhrnné hlášení DPHSHV)
 *        - dpfdp5.xsd  (Daň z příjmů FO)
 *        - dppdp9.xsd  (Daň z příjmů PO)
 *
 * Bez schématu validation vrátí `status=skipped` — XML se stále archivuje a stahuje
 * normálně, jen UI nevarujem před chybami.
 *
 * **Pozn.:** Schémata nezahrnujeme do repo kvůli velikosti a licenci MFČR.
 */
final class XmlSchemaValidator
{
    /**
     * @return array{status: 'passed'|'failed'|'skipped', errors: list<string>}
     */
    public function validate(string $xml, string $formCode): array
    {
        $schemaPath = $this->resolveSchemaPath($formCode);
        if ($schemaPath === null || !is_file($schemaPath)) {
            return ['status' => 'skipped', 'errors' => []];
        }

        $errors = [];

        // PHP libxml errors collector
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml);
        if (!$loaded) {
            foreach (libxml_get_errors() as $err) {
                $errors[] = trim($err->message) . ' (line ' . $err->line . ')';
            }
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            return ['status' => 'failed', 'errors' => $errors];
        }

        $valid = @$dom->schemaValidate($schemaPath);
        if (!$valid) {
            foreach (libxml_get_errors() as $err) {
                $errors[] = trim($err->message) . ' (line ' . $err->line . ', column ' . $err->column . ')';
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return [
            'status' => $valid ? 'passed' : 'failed',
            'errors' => array_slice($errors, 0, 50), // cap pro DB JSON column size
        ];
    }

    /**
     * Zda je schema dostupné pro daný form_code (pro UI hint).
     */
    public function hasSchema(string $formCode): bool
    {
        $path = $this->resolveSchemaPath($formCode);
        return $path !== null && is_file($path);
    }

    private function resolveSchemaPath(string $formCode): ?string
    {
        // Whitelist form codes — zabranit path injection
        $allowed = ['dphdp3', 'dphkh1', 'dphshv', 'dpfdp5', 'dppdp9'];
        if (!in_array($formCode, $allowed, true)) {
            return null;
        }
        return Bootstrap::rootDir() . '/storage/xsd/' . $formCode . '.xsd';
    }
}
