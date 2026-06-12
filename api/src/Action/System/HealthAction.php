<?php

declare(strict_types=1);

namespace MyInvoice\Action\System;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly RedisProbe $redis,
        private readonly SecretEncryption $crypto,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $payload = [
            'status'  => 'ok',
            'version' => '0.1.0',
            'db'      => $this->db->ping(),
            'redis'   => $this->redis->isAvailable(),
            'time'    => date(\DateTimeInterface::ATOM),
        ];

        // Diagnostické warningy (např. slabý fallback secret_encryption_key) jen
        // pro přihlášené — anonymnímu volajícímu (Docker healthcheck, monitoring)
        // neprozrazujeme detaily konfigurace.
        if ($request->getAttribute(AuthMiddleware::ATTR_USER) !== null) {
            $warnings = [];
            $keyWarning = $this->crypto->validateKey();
            if ($keyWarning !== null) {
                $warnings[] = [
                    'code' => 'secret_encryption_key',
                    'message' => $keyWarning,
                ];
            }
            $payload['warnings'] = $warnings;
        }

        return Json::ok($response, $payload);
    }
}
