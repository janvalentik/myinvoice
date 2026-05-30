<?php

declare(strict_types=1);

namespace MyInvoice\Action\AresVies;

use MyInvoice\Http\Json;
use MyInvoice\Service\Ares\CrpDphClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/clients/lookup-bank  body: { dic: string }
 *
 * Autentizovaný lookup zveřejněných bankovních účtů z registru plátců DPH
 * (CRPDPH/MFČR) podle DIČ + příznak nespolehlivého plátce.
 */
final class CrpDphLookupAction
{
    public function __construct(private readonly CrpDphClient $crpdph) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $dic = preg_replace('/\D/', '', (string) ($body['dic'] ?? '')) ?? '';
        if (!preg_match('/^\d{8,10}$/', $dic)) {
            return Json::error($response, 'invalid_dic', 'DIČ musí mít 8–10 číslic (např. CZ12345678).', 400);
        }

        return Json::ok($response, $this->crpdph->lookup($dic));
    }
}
