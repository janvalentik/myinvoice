<?php

declare(strict_types=1);

namespace MyInvoice\Action\Maintenance;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Sample\SampleDataService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa ukázkových (sample) dat — zjištění a přesné odebrání (issue #162).
 *
 *   GET    /api/maintenance/sample-data   → { has, total, counts }
 *   DELETE /api/maintenance/sample-data   → smaže evidovaná sample data, vrátí počty
 *
 * Admin-only (RoleMiddleware: neuvedená cesta → admin fallback; navíc defensivní kontrola tady).
 */
final class SampleDataAction
{
    public function __construct(
        private readonly SampleDataService $service,
        private readonly Connection $db,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Jen admin.', 403);
        }
        return Json::ok($response, $this->service->summary($this->supplierId($request)));
    }

    public function delete(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Jen admin.', 403);
        }
        $supplierId = $this->supplierId($request);
        if (!$this->service->hasSampleData($supplierId)) {
            return Json::error($response, 'no_sample_data', 'Žádná ukázková data k odebrání.', 404);
        }
        try {
            $deleted = $this->service->purge($supplierId);
        } catch (\Throwable $e) {
            return Json::error($response, 'sample_purge_failed', $e->getMessage(), 409);
        }
        return Json::ok($response, ['deleted' => $deleted]);
    }

    private function isAdmin(Request $request): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ($user['role'] ?? '') === 'admin';
    }

    private function supplierId(Request $request): int
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid > 0) return $sid;
        // Fallback (single-tenant / chybějící scope): první supplier.
        return (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
    }
}
