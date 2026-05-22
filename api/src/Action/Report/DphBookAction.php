<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\DphBookPdfRenderer;
use MyInvoice\Service\Report\DphBookBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kniha DPH — interní VAT žurnál (NE EPO podání).
 *
 *   GET /api/reports/dph-book/preview?year=2026&month=4 → JSON struktura
 *   GET /api/reports/dph-book?year=2026&month=4         → PDF download
 *
 * **NEpoužívá TaxSubmissionArchiver** — toto není podání FÚ, jen interní
 * pomůcka pro účetní (paste-and-modify z KontrolniHlaseniAction bez archivace).
 */
final class DphBookAction
{
    public function __construct(
        private readonly DphBookBuilder $builder,
        private readonly DphBookPdfRenderer $renderer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        [$year, $month] = $this->parsePeriod($request);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        try {
            $result = $this->builder->build($supplierId, $year, $month);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
        return Json::ok($response, $result);
    }

    public function download(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        [$year, $month] = $this->parsePeriod($request);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        try {
            $data = $this->builder->build($supplierId, $year, $month);
            $pdf = $this->renderer->render($data);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('report.dph_book_downloaded', $userId, null, null, [
            'period' => sprintf('%04d-%02d', $year, $month),
            'sections' => count($data['sections']),
            'total_rows' => array_sum(array_map(fn ($s) => count($s['rows']), $data['sections'])),
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = sprintf('kniha-dph-%04d-%02d.pdf', $year, $month);
        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array{0:int|null, 1:int|null}
     */
    private function parsePeriod(Request $request): array
    {
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return [null, null];
        }
        return [$year, $month];
    }
}
