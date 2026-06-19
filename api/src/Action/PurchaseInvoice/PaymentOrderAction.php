<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payment\PaymentOrderService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Platební příkazy pro přijaté faktury.
 *
 *   GET    /api/purchase-invoices/payment-orders/candidates?currency=CZK  → kandidáti + účty plátce (read)
 *   POST   /api/purchase-invoices/payment-orders                          → vytvoř dávku (write)
 *   GET    /api/purchase-invoices/payment-orders                          → historie dávek (read)
 *   GET    /api/purchase-invoices/payment-orders/{id}                     → detail dávky (read)
 *   GET    /api/purchase-invoices/payment-orders/{id}/download?format=abo|csv|pdf → soubor (read)
 *
 * Zápisové operace (POST) blokuje RoleMiddleware pro readonly uživatele dle metody.
 */
final class PaymentOrderAction
{
    public function __construct(
        private readonly PaymentOrderService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** GET candidates + payer accounts. */
    public function candidates(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $currency = $request->getQueryParams()['currency'] ?? null;
        $currency = is_string($currency) && $currency !== '' ? $currency : null;
        return Json::ok($response, $this->service->candidates($supplierId, $currency));
    }

    /** POST — vytvoř (ulož) platební příkaz. */
    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;

        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->service->create($supplierId, [
                'invoice_ids'       => (array) ($body['invoice_ids'] ?? []),
                'payer_currency_id' => (int) ($body['payer_currency_id'] ?? 0),
                'payment_date'      => (string) ($body['payment_date'] ?? ''),
                'constant_symbol'   => $body['constant_symbol'] ?? null,
                'note'              => $body['note'] ?? null,
                'mark_paid'         => (bool) ($body['mark_paid'] ?? false),
            ], $userId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('payment_order.created', $userId, 'payment_order', $result['order_id'], [
            'item_count' => $result['view']['item_count'] ?? 0,
            'total'      => $result['view']['total_amount'] ?? 0,
            'currency'   => $result['view']['currency'] ?? null,
            'mark_paid'  => $result['view']['mark_paid'] ?? false,
            'skipped'    => count($result['skipped']),
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, $result, 201);
    }

    /** GET — on-demand kontrola účtu faktury proti zveřejněným účtům plátce DPH (CRPDPH). */
    public function verifyAccount(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $invoiceId = (int) ($request->getQueryParams()['invoice_id'] ?? 0);
        $res = $this->service->verifyInvoiceAccount($supplierId, $invoiceId);
        if ($res === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        return Json::ok($response, $res);
    }

    /** POST — „Jen označit": zařadit vybrané faktury k úhradě bez exportu. */
    public function markOrdered(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;

        $body = (array) ($request->getParsedBody() ?? []);
        $ids = (array) ($body['invoice_ids'] ?? []);
        if ($ids === []) {
            return Json::error($response, 'validation_failed', 'Není vybrána žádná faktura.', 422);
        }
        $count = $this->service->markOrdered($supplierId, $ids, (bool) ($body['mark_paid'] ?? false));

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('payment_order.marked', $userId, null, null, [
            'count'     => $count,
            'mark_paid' => (bool) ($body['mark_paid'] ?? false),
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, ['count' => $count]);
    }

    /** GET — historie dávek. */
    public function history(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, ['data' => $this->service->history($supplierId)]);
    }

    /** GET — detail dávky (vč. položek). */
    public function show(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $view = $this->service->view($id, $supplierId);
        if ($view === null) {
            return Json::error($response, 'not_found', 'Platební příkaz nenalezen.', 404);
        }
        return Json::ok($response, $view);
    }

    /** GET — stažení souboru (csv/pdf/abo). */
    public function download(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $format = strtolower((string) ($request->getQueryParams()['format'] ?? 'abo'));

        try {
            $file = $this->service->download($id, $supplierId, $format);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'export_failed', $e->getMessage(), 422);
        }
        if ($file === null) {
            return Json::error($response, 'not_found', 'Platební příkaz nenalezen.', 404);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('payment_order.exported', null, 'payment_order', $id, [
            'format' => $format,
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        $response->getBody()->write($file['bytes']);
        return $response
            ->withHeader('Content-Type', $file['content_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"');
    }
}
