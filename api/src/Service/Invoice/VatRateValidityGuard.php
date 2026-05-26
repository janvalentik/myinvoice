<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use PDO;

/**
 * Ověří, že přišpendlené sazby DPH jsou platné k datu plnění (DUZP).
 *
 * Sazby se v `vat_rates` modelují jako záznamy s valid_from/valid_to — změna sazby
 * (např. 21 % → 22 %) = NOVÝ řádek + nastavení valid_to na starém. Doklady, které
 * sazbu drží přes pevné `vat_rate_id` (recurring šablony, klonované faktury), tak
 * můžou ukazovat na VYPRŠELÝ řádek a tiše vystavit fakturu se starou sazbou.
 *
 * Tento guard takový případ zachytí PŘED vytvořením dokladu a vyhodí DomainException
 * s jasnou hláškou — uživatel pak ve zdrojové faktuře / šabloně vybere aktuální sazbu.
 */
final class VatRateValidityGuard
{
    /**
     * @param iterable<int|string> $vatRateIds  vat_rate_id položek dokladu
     * @throws \DomainException pokud některá sazba není platná k $referenceDate
     */
    public static function assertValidOn(PDO $pdo, iterable $vatRateIds, string $referenceDate): void
    {
        $ids = [];
        foreach ($vatRateIds as $id) {
            $id = (int) $id;
            if ($id > 0) $ids[$id] = $id;
        }
        if ($ids === []) {
            return;
        }

        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, code, label_cs, valid_from, valid_to FROM vat_rates WHERE id IN ($place)"
        );
        $stmt->execute(array_values($ids));
        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byId[(int) $r['id']] = $r;
        }

        $invalid = [];
        foreach ($ids as $id) {
            $r = $byId[$id] ?? null;
            if ($r === null) {
                $invalid[] = "#{$id}";
                continue;
            }
            $from = (string) $r['valid_from'];
            $to = $r['valid_to'] !== null ? (string) $r['valid_to'] : null;
            if (($from !== '' && $from > $referenceDate) || ($to !== null && $to < $referenceDate)) {
                $label = trim(((string) $r['code']) . ' ' . ((string) $r['label_cs']));
                $invalid[] = $label . ($to !== null ? " (platnost do {$to})" : '');
            }
        }

        if ($invalid !== []) {
            throw new \DomainException(
                'Sazba DPH není platná k datu plnění ' . $referenceDate . ': '
                . implode(', ', $invalid)
                . '. Uprav doklad/šablonu a vyber aktuální sazbu DPH.'
            );
        }
    }
}
