<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Práce s evidencí ukázkových (sample) dat — zjištění existence, souhrn a přesné odebrání.
 *
 * Sample data (klienti, zakázky, faktury, dobropisy, dodavatelé, přijaté faktury,
 * pravidelné fakturace, kniha jízd) zapisuje {@see SampleDataGenerator} a každou kořenovou
 * entitu eviduje v tabulce `sample_data_entries`. Díky tomu je lze později smazat na milimetr
 * přesně (issue #162) a tatáž evidence řídí zobrazení tlačítka „Odebrat ukázková data" v UI.
 *
 * Mazání respektuje FK: entity s RESTRICT vazbou na clients (invoices, projects,
 * purchase_invoices, recurring) se mažou PŘED klienty; fuelings PŘED autem (auto je jinak
 * jen SET NULL, tankování by osiřela). Zbytek (items, PDF, cache, dobropisy přes
 * parent_invoice_id) padá kaskádou. Celé v jedné transakci — když je některá sample entita
 * navázaná na uživatelova reálná data (RESTRICT), purge se vrátí celý a nahlásí to.
 */
final class SampleDataService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /** Existují pro daného dodavatele evidovaná sample data? (řídí zobrazení tlačítka v UI) */
    public function hasSampleData(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS(SELECT 1 FROM sample_data_entries WHERE supplier_id = ?)'
        );
        $stmt->execute([$supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Souhrn evidovaných sample entit dle typu (co bylo vygenerováno).
     *
     * @return array{has:bool, total:int, counts:array<string,int>}
     */
    public function summary(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT entity_type, COUNT(*) AS cnt
               FROM sample_data_entries
              WHERE supplier_id = ?
           GROUP BY entity_type'
        );
        $stmt->execute([$supplierId]);

        $counts = [];
        $total = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c = (int) $row['cnt'];
            $counts[(string) $row['entity_type']] = $c;
            $total += $c;
        }

        return ['has' => $total > 0, 'total' => $total, 'counts' => $counts];
    }

    /**
     * Smaže všechna evidovaná sample data daného dodavatele. Vrací počty skutečně smazaných
     * řádků po hlavních tabulkách. Atomické — při FK konfliktu (sample entita navázaná na
     * reálná data) se transakce vrátí a vyhodí výjimku.
     *
     * @return array{clients:int, projects:int, invoices:int, purchase_invoices:int, recurring:int, cars:int, fuelings:int, trips:int}
     */
    public function purge(int $supplierId): array
    {
        $pdo = $this->db->pdo();

        $idsOf = function (array $types) use ($pdo, $supplierId): array {
            $place = implode(',', array_fill(0, count($types), '?'));
            $stmt = $pdo->prepare(
                "SELECT entity_id FROM sample_data_entries
                  WHERE supplier_id = ? AND entity_type IN ($place)"
            );
            $stmt->execute([$supplierId, ...$types]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        };

        $invoiceIds  = $idsOf(['invoice', 'credit_note']);
        $projectIds  = $idsOf(['project']);
        $clientIds   = $idsOf(['client', 'vendor']);
        $purchaseIds = $idsOf(['purchase_invoice']);
        $recurringIds = $idsOf(['recurring_template']);
        $carIds      = $idsOf(['car']);

        // Smaže rodičovské řádky podle id seznamu; děti padají kaskádou (viz třídní docblock).
        // $hasSupplierCol=false pro tabulky bez supplier_id (projects se váže přes client_id);
        // id pocházejí z sample_data_entries, takže jsou už scoped na tohoto dodavatele.
        $deleteByIds = function (string $table, array $ids, bool $hasSupplierCol = true) use ($pdo, $supplierId): int {
            if ($ids === []) return 0;
            $place = implode(',', array_fill(0, count($ids), '?'));
            if ($hasSupplierCol) {
                $stmt = $pdo->prepare("DELETE FROM `$table` WHERE supplier_id = ? AND id IN ($place)");
                $stmt->execute([$supplierId, ...$ids]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id IN ($place)");
                $stmt->execute($ids);
            }
            return $stmt->rowCount();
        };

        $result = [
            'clients' => 0, 'projects' => 0, 'invoices' => 0, 'purchase_invoices' => 0,
            'recurring' => 0, 'cars' => 0, 'fuelings' => 0, 'trips' => 0,
        ];

        $pdo->beginTransaction();
        try {
            // 1) Kniha jízd: tankování PŘED autem (fk_fuelings_car je SET NULL → jinak osiří),
            //    trips padají kaskádou s autem.
            if ($carIds !== []) {
                $place = implode(',', array_fill(0, count($carIds), '?'));
                $delFuel = $pdo->prepare(
                    "DELETE FROM fuelings WHERE supplier_id = ? AND car_id IN ($place)"
                );
                $delFuel->execute([$supplierId, ...$carIds]);
                $result['fuelings'] = $delFuel->rowCount();

                $cntTrips = $pdo->prepare(
                    "SELECT COUNT(*) FROM trips WHERE supplier_id = ? AND car_id IN ($place)"
                );
                $cntTrips->execute([$supplierId, ...$carIds]);
                $result['trips'] = (int) $cntTrips->fetchColumn();

                $result['cars'] = $deleteByIds('cars', $carIds); // kaskáduje trips
            }

            // 2) Faktury + dobropisy (dobropis padá kaskádou přes parent_invoice_id, ale máme
            //    je evidované zvlášť → smažou se i tak). Kaskáduje items/PDF/přílohy/platby.
            $result['invoices'] = $deleteByIds('invoices', $invoiceIds);

            // 3) Pravidelné fakturace (kaskáduje items) — před klienty (RESTRICT).
            $result['recurring'] = $deleteByIds('recurring_invoice_templates', $recurringIds);

            // 4) Přijaté faktury (kaskáduje items/scans/matches) — před dodavateli (RESTRICT).
            $result['purchase_invoices'] = $deleteByIds('purchase_invoices', $purchaseIds);

            // 5) Zakázky — po fakturách (RESTRICT), kaskáduje billing_emails/revenue_cache.
            //    projects nemá supplier_id (váže se přes client_id) → maž jen podle id.
            $result['projects'] = $deleteByIds('projects', $projectIds, false);

            // 6) Klienti + dodavatelé — naposled, kaskáduje email_contacts/revenue_cache.
            $result['clients'] = $deleteByIds('clients', $clientIds);

            // 7) Vyčisti evidenci.
            $pdo->prepare('DELETE FROM sample_data_entries WHERE supplier_id = ?')
                ->execute([$supplierId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new \RuntimeException(
                'Ukázková data se nepodařilo odebrat — některý sample záznam je navázaný na '
                . 'vaše vlastní data. Odeberte nejdřív tu vazbu, nebo použijte úplný reset '
                . '(`php api/bin/reset.php --keep-users-supplier`). Detail: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $result;
    }
}
