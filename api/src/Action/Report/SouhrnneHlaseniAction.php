<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\SouhrnneHlaseniBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Souhrnné hlášení DPHSHV1 endpoints (měsíční, podávají plátci i identifikované osoby):
 *   GET /api/reports/dphshv/preview?year=2026&month=5 → JSON summary
 *   GET /api/reports/dphshv?year=2026&month=5         → XML download
 */
final class SouhrnneHlaseniAction
{
    public function __construct(
        private readonly SouhrnneHlaseniBuilder $builder,
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
        return Json::ok($response, ['summary' => $result['summary'], 'warnings' => $result['warnings']]);
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
            $result = $this->builder->build($supplierId, $year, $month);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('report.dphshv_downloaded', $userId, null, null, [
            'period' => sprintf('%04d-%02d', $year, $month),
            'rows'   => $result['summary']['rows_count'] ?? 0,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = sprintf('dphshv-%04d-%02d.xml', $year, $month);
        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
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
