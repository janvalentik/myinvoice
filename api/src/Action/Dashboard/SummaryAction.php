<?php

declare(strict_types=1);

namespace MyInvoice\Action\Dashboard;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Agregace pro Dashboard:
 *  - KPI: letošní obrat per měna, YoY change, počet vystavených, průměrná doba úhrady
 *  - Po splatnosti: tabulka faktur s due_date < today, status issued/sent
 *  - Nezaplacené (před splatností)
 *  - Top klienti YTD
 *  - Obrat po měsících (12 měsíců současný + minulý rok)
 *
 * Storno (cancelled) a interní cancellation se z obratu vyřazují.
 */
final class SummaryAction
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        $today = new \DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $prevYear = $year - 1;
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        return Json::ok($response, [
            'kpi'                    => $this->kpi($pdo, $year, $prevYear, $sid),
            'overdue'                => $this->overdue($pdo, $sid),
            'unpaid_upcoming'        => $this->unpaidUpcoming($pdo, $sid),
            'top_clients_ytd'        => $this->topClients($pdo, $year, $sid),
            'top_clients_prev_year'  => $this->topClients($pdo, $prevYear, $sid),
            'revenue_by_month'       => $this->revenueByMonth($pdo, $sid),
            'pending_approvals'      => $this->pendingApprovals($pdo, $sid),
            'today'                  => $today->format('Y-m-d'),
            'year'                   => $year,
            'prev_year'              => $prevYear,
        ]);
    }

    /**
     * Schvalování výkazu zákazníkem — count requested + overdue (>5 dní).
     * Klik na tile → /admin/approvals.
     * @return array{requested: int, overdue: int}
     */
    private function pendingApprovals(\PDO $pdo, int $sid): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN approval_status = 'requested' THEN 1 ELSE 0 END) AS requested,
                SUM(CASE WHEN approval_status = 'requested'
                          AND COALESCE(approval_reminder_at, approval_requested_at)
                              <= DATE_SUB(NOW(), INTERVAL 5 DAY) THEN 1 ELSE 0 END) AS overdue
              FROM invoices
             WHERE supplier_id = ?"
        );
        $stmt->execute([$sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['requested' => 0, 'overdue' => 0];
        return [
            'requested' => (int) ($row['requested'] ?? 0),
            'overdue'   => (int) ($row['overdue'] ?? 0),
        ];
    }

    private function kpi(\PDO $pdo, int $year, int $prevYear, int $sid): array
    {
        // Obrat per měna pro YTD (letošní vs. minulý rok)
        // Záměrně počítáme i NEZAPLACENÉ faktury, pokud jsou vystavené (status: issued / sent / paid).
        // Dobropisy (credit_note) ZAHRNUJEME — mají záporné total_with_vat (viz CancelInvoiceAction),
        // takže se SUMou automaticky odečtou od obratu. Koncepty (draft) a zálohovky (proforma) nezapočítáváme.
        //
        // change_pct: porovnává this_year (YTD) s prev_year_ytd — tj. minulý rok jen do stejné kalendářní
        // pozice (DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) — fair YoY pro nedokončený aktuální rok.
        // prev_year zůstává jako celoroční total pro kontext (zobrazení v UI / fallback grafy).
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN i.total_with_vat ELSE 0 END) AS this_year,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN i.total_with_vat ELSE 0 END) AS prev_year,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                  AND COALESCE(i.tax_date, i.issue_date) <= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                                 THEN i.total_with_vat ELSE 0 END) AS prev_year_ytd
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND YEAR(COALESCE(i.tax_date, i.issue_date)) IN (?, ?)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$year, $prevYear, $prevYear, $sid, $year, $prevYear]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $perCurrency = [];
        foreach ($rows as $r) {
            $thisYear = (float) $r['this_year'];
            $prevYearTotal = (float) $r['prev_year'];
            $prevYearYtd = (float) $r['prev_year_ytd'];
            $changePct = null;
            if ($prevYearYtd > 0) {
                $changePct = round((($thisYear - $prevYearYtd) / $prevYearYtd) * 100, 1);
            }
            $perCurrency[(string) $r['currency']] = [
                'currency'      => (string) $r['currency'],
                'this_year'     => round($thisYear, 2),
                'prev_year'     => round($prevYearTotal, 2),
                'prev_year_ytd' => round($prevYearYtd, 2),
                'change_pct'    => $changePct,
            ];
        }

        // Počet vystavených YTD — proformy se nezapočítávají (nejde o finální daňový doklad).
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM invoices
              WHERE supplier_id = ?
                AND YEAR(COALESCE(tax_date, issue_date)) = ?
                AND status NOT IN ('draft', 'cancelled')
                AND invoice_type IN ('invoice', 'credit_note')"
        );
        $stmt->execute([$sid, $year]);
        $issuedCount = (int) $stmt->fetchColumn();

        // Po splatnosti — počet a celkem k úhradě
        $stmt = $pdo->prepare(
            "SELECT cur.code AS currency, COUNT(*) AS cnt, SUM(i.amount_to_pay) AS total
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued','sent','reminded') AND i.due_date <= CURDATE()
                AND i.invoice_type IN ('invoice','credit_note')
              GROUP BY cur.code"
        );
        $stmt->execute([$sid]);
        $overdue = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $overduePerCurrency = array_map(fn (array $r) => [
            'currency' => $r['currency'],
            'count'    => (int) $r['cnt'],
            'total'    => round((float) $r['total'], 2),
        ], $overdue);
        $overdueTotalCount = array_sum(array_column($overduePerCurrency, 'count'));

        // Průměrná doba úhrady (paid_at - issue_date) ve dnech, pro letošní zaplacené
        $stmt = $pdo->prepare(
            "SELECT AVG(DATEDIFF(paid_at, issue_date)) FROM invoices
              WHERE supplier_id = ? AND status = 'paid' AND paid_at IS NOT NULL
                AND YEAR(COALESCE(tax_date, issue_date)) = ?"
        );
        $stmt->execute([$sid, $year]);
        $avgPaymentDays = $stmt->fetchColumn();
        $avgPaymentDays = $avgPaymentDays !== null && $avgPaymentDays !== false
            ? round((float) $avgPaymentDays, 1)
            : null;

        // Stav faktur YTD (počet) — pro fallback chart když není prev year
        $stmt = $pdo->prepare(
            "SELECT status, COUNT(*) AS cnt
               FROM invoices
              WHERE supplier_id = ?
                AND YEAR(COALESCE(tax_date, issue_date)) = ?
                AND invoice_type = 'invoice'
              GROUP BY status"
        );
        $stmt->execute([$sid, $year]);
        $statusCounts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $statusCounts[$r['status']] = (int) $r['cnt'];
        }

        return [
            'per_currency'        => array_values($perCurrency),
            'issued_count_ytd'    => $issuedCount,
            'overdue_count'       => $overdueTotalCount,
            'overdue_per_currency'=> $overduePerCurrency,
            'avg_payment_days'    => $avgPaymentDays,
            'status_counts_ytd'   => $statusCounts,
        ];
    }

    private function overdue(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.client_id, cur.code AS currency,
                       i.issue_date, i.due_date, i.amount_to_pay, i.status,
                       c.company_name AS client_company_name,
                       DATEDIFF(CURDATE(), i.due_date) AS days_overdue
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.due_date <= CURDATE()
                   AND i.invoice_type IN ('invoice','credit_note')
                 ORDER BY i.due_date ASC
                 LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castListItem($r), $rows);
    }

    private function unpaidUpcoming(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.client_id, cur.code AS currency,
                       i.issue_date, i.due_date, i.amount_to_pay, i.status,
                       c.company_name AS client_company_name
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.due_date >= CURDATE()
                   AND i.invoice_type IN ('invoice','credit_note')
                 ORDER BY i.due_date ASC
                 LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castListItem($r), $rows);
    }

    private function topClients(\PDO $pdo, int $year, int $sid): array
    {
        $sql = "SELECT c.id, c.company_name, cur.code AS currency,
                       SUM(i.total_with_vat) AS total,
                       COUNT(*) AS invoice_count
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY c.id, c.company_name, cur.code
                 ORDER BY total DESC
                 LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid, $year]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => [
            'client_id'     => (int) $r['id'],
            'company_name'  => $r['company_name'],
            'currency'      => $r['currency'],
            'total'         => round((float) $r['total'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $rows);
    }

    /**
     * Obrat za posledních 12 měsíců (rolling window končící aktuálním měsícem) + porovnávací řada
     * pro stejných 12 měsíců o rok dříve (–1 rok), per měna.
     *
     * Output: [
     *   { currency: 'CZK',
     *     months:    [ { ym: 'YYYY-MM', total: 0.0 }, ... 12 entries ascending ],
     *     prev_year: [ { ym: 'YYYY-MM', total: 0.0 }, ... 12 entries ascending, –12 měsíců ] },
     *   ...
     * ]
     */
    private function revenueByMonth(\PDO $pdo, int $sid): array
    {
        // Okno aktuálních 12 měsíců + 12 měsíců o rok dříve = celkem 24 měsíců dat.
        // Začátek = (dnes − 23 měsíců, 1. den měsíce).
        $sql = "SELECT cur.code AS currency,
                       DATE_FORMAT(COALESCE(i.tax_date, i.issue_date), '%Y-%m') AS ym,
                       SUM(i.total_with_vat) AS total
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND COALESCE(i.tax_date, i.issue_date) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 23 MONTH), '%Y-%m-01')
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code, ym";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Sloty: aktuální 12 měsíců (months) + 12 měsíců o rok dříve (prev_year).
        $monthsSlots = [];
        $prevSlots = [];
        $cursor = new \DateTimeImmutable(date('Y-m-01'));
        $cursorThis = $cursor->modify('-11 months');
        $cursorPrev = $cursor->modify('-23 months');
        for ($i = 0; $i < 12; $i++) {
            $monthsSlots[$cursorThis->format('Y-m')] = 0.0;
            $prevSlots[$cursorPrev->format('Y-m')]   = 0.0;
            $cursorThis = $cursorThis->modify('+1 month');
            $cursorPrev = $cursorPrev->modify('+1 month');
        }

        // Skupina per měna — totaly přiřaď do správného slotu (current vs. prev) dle YYYY-MM klíče.
        $perCurrency = [];
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            $ym = (string) $r['ym'];
            $total = round((float) $r['total'], 2);
            if (!isset($perCurrency[$cur])) {
                $perCurrency[$cur] = ['months' => $monthsSlots, 'prev_year' => $prevSlots];
            }
            if (array_key_exists($ym, $perCurrency[$cur]['months'])) {
                $perCurrency[$cur]['months'][$ym] = $total;
            } elseif (array_key_exists($ym, $perCurrency[$cur]['prev_year'])) {
                $perCurrency[$cur]['prev_year'][$ym] = $total;
            }
        }

        $toList = static fn (array $slots): array => array_map(
            static fn ($ym, $t) => ['ym' => $ym, 'total' => $t],
            array_keys($slots),
            $slots
        );

        $out = [];
        foreach ($perCurrency as $cur => $data) {
            $out[] = [
                'currency'  => $cur,
                'months'    => $toList($data['months']),
                'prev_year' => $toList($data['prev_year']),
            ];
        }
        return $out;
    }

    private function castListItem(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'varsymbol'           => $r['varsymbol'],
            'invoice_type'        => $r['invoice_type'],
            'client_id'           => (int) $r['client_id'],
            'client_company_name' => $r['client_company_name'],
            'currency'            => $r['currency'],
            'issue_date'          => $r['issue_date'],
            'due_date'            => $r['due_date'],
            'amount_to_pay'       => (float) $r['amount_to_pay'],
            'status'              => $r['status'],
            'days_overdue'        => isset($r['days_overdue']) ? (int) $r['days_overdue'] : null,
        ];
    }
}
