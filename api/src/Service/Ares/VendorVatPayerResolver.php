<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ares;

use MyInvoice\Repository\ClientRepository;

/**
 * Zjistí, zda je dodavatel plátce DPH, z autoritativních registrů a uloží příznak
 * na klienta (`clients.is_vat_payer`). Sdílí ho AI import, online refresh endpoint
 * i backfill skript — jediné místo s pravidlem.
 *
 * Zdroj podle typu dodavatele (precedence):
 *  1. CZ (IČO 8 číslic)        → ARES `is_vat_payer` (stavZdrojeDph === 'AKTIVNI').
 *  2. Zahraniční EU (jen DIČ)  → VIES `valid` (registrované DIČ = plátce).
 *  3. Nezjištěno (ARES/VIES nedostupné / 404 / mimo EU) → null (necháme dosavadní
 *     příznak beze změny; klasifikovat neplátce smíme jen z pozitivního výsledku).
 *
 * Cache: ARES (ares_cache 24 h) i VIES (vies_cache) řeší TTL na úrovni klientů →
 * volání při každém vytvoření faktury je fakticky „1× denně" bez zátěže registrů.
 */
final class VendorVatPayerResolver
{
    public function __construct(
        private readonly AresClient $ares,
        private readonly ViesClient $vies,
        private readonly ClientRepository $clients,
    ) {}

    /**
     * Zjistí plátcovství a (pokud je výsledek jednoznačný) uloží ho na klienta.
     *
     * @return array{is_vat_payer:?bool, source:'ares'|'vies'|'unknown'}
     */
    public function resolveAndPersist(int $clientId, ?string $ic, ?string $dic): array
    {
        $res = $this->resolve($ic, $dic);
        if ($res['is_vat_payer'] !== null) {
            $this->clients->setVatPayer($clientId, $res['is_vat_payer']);
        }
        return $res;
    }

    /**
     * Pure lookup bez zápisu — vrací is_vat_payer (true/false) nebo null (nezjištěno).
     *
     * @return array{is_vat_payer:?bool, source:'ares'|'vies'|'unknown'}
     */
    public function resolve(?string $ic, ?string $dic): array
    {
        $icDigits = preg_replace('/\D/', '', (string) $ic) ?? '';

        // 1. CZ subjekt dle IČO → ARES (autoritativní stav registrace DPH).
        if (strlen($icDigits) === 8) {
            $resp = $this->ares->lookup($icDigits);
            if ($resp !== null && ($resp['found'] ?? false) && isset($resp['data'])) {
                return ['is_vat_payer' => (bool) ($resp['data']['is_vat_payer'] ?? false), 'source' => 'ares'];
            }
        }

        // 2. Zahraniční EU dle DIČ → VIES (valid = registrovaný plátce). Jen když ARES
        //    nerozhodl. source='error' = VIES nedostupné → nevíme (null), neklasifikuj.
        $dicTrim = trim((string) $dic);
        if ($dicTrim !== '') {
            try {
                $v = $this->vies->lookup($dicTrim);
                if (($v['source'] ?? '') !== 'error') {
                    return ['is_vat_payer' => !empty($v['valid']), 'source' => 'vies'];
                }
            } catch (\Throwable) {
                // VIES timeout / chyba — necháme nezjištěno.
            }
        }

        return ['is_vat_payer' => null, 'source' => 'unknown'];
    }
}
