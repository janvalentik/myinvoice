<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatClassificationMapper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DPH přiznání DPHDP3 endpoints:
 *   GET /api/reports/dphdp3/preview?year=2026&month=5  — JSON summary (řádky + warnings)
 *   GET /api/reports/dphdp3?year=2026&month=5          — XML download
 *
 * Permissions: admin nebo accountant.
 *
 * ⚠️ Vygenerované XML je pomůcka. Před odesláním ověřit s účetní/poradcem.
 */
final class DphPriznaniAction
{
    public function __construct(
        private readonly DphPriznaniBuilder $builder,
        private readonly VatClassificationMapper $mapper,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Service\Report\TaxSubmissionArchiver $archiver,
    ) {}

    /**
     * GET /api/reports/dphdp3/settings → { vat_period, is_vat_payer }
     * Vrátí supplier nastavení potřebné pro UI (měsíční vs kvartální period picker).
     */
    public function settings(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_period, is_vat_payer, taxpayer_type, financial_office_code FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return Json::ok($response, [
            'vat_period'            => $row['vat_period'] ?? null,
            'is_vat_payer'          => (bool) ($row['is_vat_payer'] ?? false),
            'taxpayer_type'         => $row['taxpayer_type'] ?? null,
            'has_financial_office'  => !empty($row['financial_office_code']),
        ]);
    }

    /**
     * GET /api/reports/dphdp3/drafts-prediction?year=&month=&period= → predikce DPH
     * pro zvolené přiznací období (měsíc / kvartál). Returns:
     *   { year, month, period, vat_output, vat_input, tax_due,
     *     sale_count, sale_draft_count, purchase_count, purchase_draft_count }
     *
     * Pravidla:
     * - Období vymezeno `COALESCE(tax_date, issue_date) BETWEEN start AND end`
     *   (drafty často DUZP zatím nemají — `tax_date` může být NULL).
     * - sale (vydané): invoice_type IN (invoice, credit_note), status NOT IN
     *   (cancelled), tedy bere finalizované doklady i koncepty pro zvolené
     *   období.
     * - purchase (přijaté): status NOT IN (cancelled), bere obojí (doklady
     *   i koncepty).
     * - Multi-currency: total_vat × COALESCE(exchange_rate, 1) → CZK. Drafty
     *   bez nastaveného kurzu se počítají jako 1:1.
     *
     * Default year/month: aktuální datum. Default period: supplier.vat_period
     * (fallback 'monthly').
     */
    public function draftsPrediction(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();

        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        $period = (string) ($q['period'] ?? '');
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            $stmt = $pdo->prepare('SELECT vat_period FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $period = (string) ($stmt->fetchColumn() ?: 'monthly');
            if (!in_array($period, ['monthly', 'quarterly'], true)) $period = 'monthly';
        }

        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $startMonth = ($quarter - 1) * 3 + 1;
            $endMonth   = $quarter * 3;
            $start = sprintf('%04d-%02d-01', $year, $startMonth);
            $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))
                ->modify('last day of this month')->format('Y-m-d');
        } else {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        }

        // Multi-currency fix: drafty často nemají exchange_rate (applier ho aplikuje až
        // při issue), tak EUR draft byl počítán 1:1 jako CZK. Pro non-CZK řádky bez rate
        // dohledat nejbližší CNB kurz z `exchange_rates` cache k DUZP (fallback 1
        // pokud cache prázdná — old behavior, nevadí).
        $rateCzk =
            "CASE WHEN cur.code = 'CZK' THEN 1
                  WHEN i.exchange_rate IS NOT NULL THEN i.exchange_rate
                  ELSE COALESCE((
                        SELECT er.rate FROM exchange_rates er
                         WHERE er.currency_code = cur.code
                           AND er.rate_date <= COALESCE(i.tax_date, i.issue_date)
                      ORDER BY er.rate_date DESC LIMIT 1
                  ), 1)
             END";
        $saleStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(i.total_vat * $rateCzk), 0) AS vat,
                    COUNT(*) AS cnt,
                    SUM(CASE WHEN i.status = 'draft' THEN 1 ELSE 0 END) AS draft_cnt
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status <> 'cancelled'
                AND i.invoice_type IN ('invoice', 'credit_note')
                AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?"
        );
        $saleStmt->execute([$supplierId, $start, $end]);
        $sale = $saleStmt->fetch(\PDO::FETCH_ASSOC) ?: ['vat' => 0, 'cnt' => 0, 'draft_cnt' => 0];

        $rateCzkPi =
            "CASE WHEN cur.code = 'CZK' THEN 1
                  WHEN pi.exchange_rate IS NOT NULL THEN pi.exchange_rate
                  ELSE COALESCE((
                        SELECT er.rate FROM exchange_rates er
                         WHERE er.currency_code = cur.code
                           AND er.rate_date <= COALESCE(pi.tax_date, pi.issue_date)
                      ORDER BY er.rate_date DESC LIMIT 1
                  ), 1)
             END";
        $purchaseStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(pi.total_vat * $rateCzkPi), 0) AS vat,
                    COUNT(*) AS cnt,
                    SUM(CASE WHEN pi.status = 'draft' THEN 1 ELSE 0 END) AS draft_cnt
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status <> 'cancelled'
                AND COALESCE(pi.tax_date, pi.issue_date) BETWEEN ? AND ?"
        );
        $purchaseStmt->execute([$supplierId, $start, $end]);
        $purchase = $purchaseStmt->fetch(\PDO::FETCH_ASSOC) ?: ['vat' => 0, 'cnt' => 0, 'draft_cnt' => 0];

        $vatOutput = (float) $sale['vat'];
        $vatInput  = (float) $purchase['vat'];

        return Json::ok($response, [
            'year'                 => $year,
            'month'                => $month,
            'period'               => $period,
            'vat_output'           => $vatOutput,
            'vat_input'            => $vatInput,
            'tax_due'              => $vatOutput - $vatInput,
            'sale_count'           => (int) $sale['cnt'],
            'sale_draft_count'     => (int) $sale['draft_cnt'],
            'purchase_count'       => (int) $purchase['cnt'],
            'purchase_draft_count' => (int) $purchase['draft_cnt'],
        ]);
    }

    /**
     * GET /api/reports/dphdp3/trend?months=12 → list měsíčních souhrnů DPH
     * (output, input, due) pro graf.
     */
    public function trend(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $months = max(1, min(36, (int) ($request->getQueryParams()['months'] ?? 12)));
        return Json::ok($response, $this->mapper->monthlyDphTrend($supplierId, $months));
    }

    public function preview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }

        $period = (string) ($q['period'] ?? '');
        $period = in_array($period, ['monthly', 'quarterly'], true) ? $period : null;
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }

        return Json::ok($response, [
            'summary'  => $result['summary'],
            'warnings' => $result['warnings'],
        ]);
    }

    public function download(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }

        $period = (string) ($q['period'] ?? '');
        $period = in_array($period, ['monthly', 'quarterly'], true) ? $period : null;
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }

        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        // Archivovat + XSD validation
        $isQuarterly = ($result['summary']['period_type'] ?? 'monthly') === 'quarterly';
        $archived = $this->archiver->archive(
            $supplierId, 'dphdp3', $year,
            $isQuarterly ? null : $month,
            $isQuarterly ? (int) ceil($month / 3) : null,
            $result['xml'], $result['summary'], $userId ?: null,
        );

        $this->logger->log('report.dphdp3_downloaded', $userId, null, null, [
            'period'            => sprintf('%04d-%02d', $year, $month),
            'period_type'       => $result['summary']['period_type'] ?? 'monthly',
            'submission_id'     => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = sprintf('dphdp3-%04d-%02d.xml', $year, $month);
        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
