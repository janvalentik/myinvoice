<?php

declare(strict_types=1);

namespace MyInvoice\Action\AresVies;

use MyInvoice\Http\Json;
use MyInvoice\Service\Ares\ViesClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ViesLookupAction
{
    public function __construct(private readonly ViesClient $vies) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $vatId = strtoupper(trim((string) ($body['vat_id'] ?? '')));

        if (!$this->isValidVatId($vatId)) {
            return Json::error($response, 'invalid_vat_id', 'DIČ musí mít prefix země a 2-12 znaků (např. CZ12345678, NL123456789B01).', 400);
        }

        $result = $this->vies->lookup($vatId);
        return Json::ok($response, $result);
    }

    /**
     * Formát DIČ pro VIES: 2-písmenný kód země + 2-12 alfanumerických znaků.
     * Jen číslice NESTAČÍ — řada zemí EU má v DIČ písmeno (NL …B01, AT U…,
     * ES, FR, IE). Znaky + a * pokrývají starší irské formáty; normalizaci
     * (strtoupper, ořez mezer) řeší volající výše.
     */
    private function isValidVatId(string $vatId): bool
    {
        return (bool) preg_match('/^[A-Z]{2}[A-Z0-9+*]{2,12}$/', $vatId);
    }
}
