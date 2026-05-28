<?php

declare(strict_types=1);

namespace MyInvoice\Action\Search;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/search?q=...  — globální vyhledávač pro sidebar.
 *
 * Vrací klienty/dodavatele (název + e-mail) a vystavené i přijaté faktury
 * (číslo dokladu). Položky menu se našeptávají client-side, sem nejdou.
 * Tenant scope přes SupplierGuard. Min. 2 znaky, jinak prázdné výsledky.
 */
final class GlobalSearchAction
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchases,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $supplierId = SupplierGuard::currentId($request);

        if (mb_strlen($q) < 2) {
            return Json::ok($response, ['q' => $q, 'clients' => [], 'invoices' => [], 'purchase_invoices' => []]);
        }

        return Json::ok($response, [
            'q'                 => $q,
            'clients'           => $this->clients->searchQuick($q, $supplierId),
            'invoices'          => $this->invoices->searchQuick($q, $supplierId),
            'purchase_invoices' => $this->purchases->searchQuick($q, $supplierId),
        ]);
    }
}
