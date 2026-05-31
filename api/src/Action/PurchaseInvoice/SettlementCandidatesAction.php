<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices/{id}/settlement-candidates
 *
 * Opačný směr párování — z detailu zálohové faktury vrátí nepropojené finální
 * (vyúčtovací) faktury stejného dodavatele, se kterými lze zálohu spárovat.
 */
final class SettlementCandidatesAction
{
    public function __construct(private readonly PurchaseInvoiceRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        return Json::ok($response, ['candidates' => $this->repo->settlementCandidates($id, $supplierId)]);
    }
}
