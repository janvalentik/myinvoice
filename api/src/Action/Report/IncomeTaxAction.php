<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\IncomeTaxBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Daň z příjmů (FO/PO) — MVP foundation, výkazy nejsou komplettní.
 *   GET /api/reports/income-tax/preview?year=2026&type=fo|po → JSON summary
 *   GET /api/reports/income-tax?year=2026&type=fo|po         → XML download
 */
final class IncomeTaxAction
{
    public function __construct(
        private readonly IncomeTaxBuilder $builder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Service\Report\TaxSubmissionArchiver $archiver,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        [$year, $type] = $this->parseParams($request);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/typ.', 400);
        }
        try {
            $result = $this->builder->build($supplierId, $year, $type);
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
        [$year, $type] = $this->parseParams($request);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/typ.', 400);
        }
        try {
            $result = $this->builder->build($supplierId, $year, $type);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $code = $type === 'fo' ? 'dpfdp5' : 'dppdp9';
        $archived = $this->archiver->archive(
            $supplierId, $code, $year, null, null,
            $result['xml'], $result['summary'], $userId ?: null,
        );
        $this->logger->log('report.income_tax_downloaded', $userId, null, null, [
            'year' => $year, 'type' => $type,
            'submission_id' => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = sprintf('%s-%04d.xml', $code, $year);
        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array{0:int|null, 1:string}
     */
    private function parseParams(Request $request): array
    {
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y') - 1);  // default = předchozí rok (daně se podávají za uplynulý)
        $type = (string) ($q['type'] ?? 'fo');
        if (!in_array($type, ['fo', 'po'], true)) return [null, 'fo'];
        if ($year < 2020 || $year > 2050) return [null, $type];
        return [$year, $type];
    }
}
