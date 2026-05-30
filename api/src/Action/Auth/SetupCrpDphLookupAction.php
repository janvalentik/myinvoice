<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ares\CrpDphClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/auth/setup-crpdph-lookup  body: { dic: string }
 *
 * Public proxy do registru plátců DPH (CRPDPH/MFČR) pro setup wizard — vrací
 * zveřejněné bankovní účty + příznak nespolehlivého plátce. Funguje POUZE dokud
 * aplikace nemá admin uživatele (analogie SetupAresLookupAction). Po dokončení
 * setupu vrací 403 a klient musí použít autentizovaný `/api/clients/lookup-bank`.
 */
final class SetupCrpDphLookupAction
{
    public function __construct(
        private readonly CrpDphClient $crpdph,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $adminCount = (int) $this->db->pdo()
            ->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")
            ->fetchColumn();
        if ($adminCount > 0) {
            return Json::error($response, 'setup_done', 'Setup je dokončený, použij /api/clients/lookup-bank.', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $dic = preg_replace('/\D/', '', (string) ($body['dic'] ?? '')) ?? '';
        if (!preg_match('/^\d{8,10}$/', $dic)) {
            return Json::error($response, 'invalid_dic', 'DIČ musí mít 8–10 číslic (např. CZ12345678).', 400);
        }

        return Json::ok($response, $this->crpdph->lookup($dic));
    }
}
