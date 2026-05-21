<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TaxSubmissionRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Historie archivovaných EPO XML výkazů (DPHDP3, DPHKH1, DPHSHV, DPFDP5, DPPDP9).
 *
 *   GET    /api/reports/submissions          → list
 *   GET    /api/reports/submissions/{id}     → detail (s XML obsahem)
 *   GET    /api/reports/submissions/{id}/xml → XML download
 *   DELETE /api/reports/submissions/{id}     → smazat archiv (admin)
 */
final class TaxSubmissionAction
{
    public function __construct(private readonly TaxSubmissionRepository $repo) {}

    public function list(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $rows = $this->repo->list($supplierId);
        return Json::ok($response, $rows);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $row = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($row === null) return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
        return Json::ok($response, $row);
    }

    public function downloadXml(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $row = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($row === null) return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);

        $monthPart = $row['period_month'] !== null
            ? sprintf('-%02d', $row['period_month'])
            : ($row['period_quarter'] !== null ? sprintf('-Q%d', $row['period_quarter']) : '');
        $filename = sprintf('%s-%d%s-archive.xml', $row['form_code'], $row['period_year'], $monthPart);

        $response->getBody()->write((string) $row['xml_content']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $ok = $this->repo->delete((int) ($args['id'] ?? 0), $supplierId);
        if (!$ok) return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
        return Json::ok($response, ['deleted' => true]);
    }
}
