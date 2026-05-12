<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Vynucení 2FA (TOTP) pro všechny uživatele.
 *
 * Pokud `cfg.auth.require_totp = true` a aktuální uživatel ještě nemá
 * `totp_enabled = 1`, blokuje všechny endpointy kromě whitelistu nutného
 * pro provedení setupu, načtení vlastního stavu a odhlášení:
 *   - GET /api/auth/me            (frontend zjistí flag must_setup_totp)
 *   - POST /api/auth/logout       (escape route)
 *   - /api/auth/totp/*            (setup, enable, status)
 *   - /api/health, /api/version   (systémové endpointy bez auth)
 *
 * Běží AŽ PO AuthMiddleware (potřebuje načteného usera). Order viz Bootstrap.
 */
final class RequireTotpMiddleware implements MiddlewareInterface
{
    public const ATTR_MUST_SETUP_TOTP = 'auth.must_setup_totp';

    private const ALLOWED_PATHS = [
        '/api/health',
        '/api/version',
        '/api/auth/me',
        '/api/auth/logout',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if (!(bool) $this->config->get('auth.require_totp', false)) {
            return $handler->handle($request);
        }

        // Bearer (API token) auth — token sám je faktor, TOTP byl vyžadován při jeho tvorbě.
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return $handler->handle($request);
        }

        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!is_array($user)) {
            // Neautentikovaný request — nech AuthMiddleware nebo public route logiku rozhodnout.
            return $handler->handle($request);
        }

        if (!empty($user['totp_enabled'])) {
            return $handler->handle($request);
        }

        $request = $request->withAttribute(self::ATTR_MUST_SETUP_TOTP, true);

        $path = $request->getUri()->getPath();
        if (in_array($path, self::ALLOWED_PATHS, true) || str_starts_with($path, '/api/auth/totp/')) {
            return $handler->handle($request);
        }

        $response = $this->responseFactory->createResponse(403);
        return Json::error($response, 'totp_setup_required', 'Pro pokračování je nutné aktivovat dvoufaktorové ověření.', 403);
    }
}
