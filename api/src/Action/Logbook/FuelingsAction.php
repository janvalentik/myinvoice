<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Logbook\FuelingOdometerEstimator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Tankování:
 *   GET    /api/logbook/fuelings      — list (?car_id=&source=&vendor_id=&year=&month=&date_from=&date_to=&unassigned=1)
 *   GET    /api/logbook/fuelings/{id}
 *   POST   /api/logbook/fuelings      — ruční záznam
 *   PUT    /api/logbook/fuelings/{id}
 *   DELETE /api/logbook/fuelings/{id}
 */
final class FuelingsAction
{
    public function __construct(
        private readonly FuelingRepository $repo,
        private readonly CarRepository $cars,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly FuelingOdometerEstimator $odometer,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $filters = array_intersect_key($q, array_flip(['car_id', 'source', 'vendor_id', 'year', 'month', 'date_from', 'date_to', 'unassigned']));
        $rows = $this->odometer->annotate($supplierId, $this->repo->listForTenant($supplierId, $filters));
        return Json::ok($response, $rows);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $f = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($f === null) return Json::error($response, 'not_found', 'Tankování nenalezeno.', 404);
        return Json::ok($response, $this->odometer->annotate($supplierId, [$f])[0]);
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($supplierId, $body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        $body['source'] = 'manual';
        $id = $this->repo->create($supplierId, $body, $this->userId($request));
        $this->log($request, 'fueling.created', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Tankování nenalezeno.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($supplierId, $body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        $this->repo->update($id, $supplierId, $body);
        $this->log($request, 'fueling.updated', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Tankování nenalezeno.', 404);
        }
        $this->repo->delete($id, $supplierId);
        $this->log($request, 'fueling.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    private function validate(int $supplierId, array $body): ?string
    {
        $date = trim((string) ($body['fueled_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Neplatné datum tankování (YYYY-MM-DD).';
        if (!isset($body['amount_with_vat']) || (float) $body['amount_with_vat'] <= 0) {
            return 'Celková částka musí být kladná.';
        }
        $carId = $this->intOrNull($body['car_id'] ?? null);
        if ($carId !== null && $this->cars->find($carId, $supplierId) === null) {
            return 'Auto neexistuje.';
        }
        return null;
    }

    private function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'fueling', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
