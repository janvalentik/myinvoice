<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\WorkReport\WorkReportLinkService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/public/work-report/{token}
 *
 * Veřejný (bez auth) endpoint pro náhled na výkaz práce.
 *
 * - Pokud má návštěvník platnou cookie-relaci → vrátí živý náhled (buildPreview).
 * - Jinak vrátí { requires_auth:true, … } s maskovanými povolenými adresami,
 *   aby si návštěvník mohl vyžádat ověřovací kód.
 */
final class PublicWorkReportGetAction
{
    public function __construct(
        private readonly WorkReportLinkService $service,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }

        $link = $this->service->findActiveLink($token);
        if ($link === null) {
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz není platný nebo byl zneplatněn.', 404);
        }

        // Přihlášený interní uživatel (admin/účetní…) vidí náhled rovnou, bez
        // e-mailové verifikace — session cookie se na /api/public/* posílá taky
        // (AuthMiddleware ji načte a nastaví usera ještě před průchodem public path).
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (is_array($user) && !empty($user['id'])) {
            $this->service->touchViewed((int) $link['id']);
            return Json::ok($response, [
                'requires_auth' => false,
                'preview'       => $this->service->buildPreview($link),
            ]);
        }

        $cookie = (string) ($request->getCookieParams()[WorkReportLinkService::COOKIE_NAME] ?? '');
        if ($cookie !== '' && $this->service->validateSession($link, $cookie)) {
            $this->service->touchViewed((int) $link['id']);
            return Json::ok($response, [
                'requires_auth' => false,
                'preview'       => $this->service->buildPreview($link),
            ]);
        }

        $supplier = $this->service->loadSupplierVars((int) $link['supplier_id']);
        return Json::ok($response, [
            'requires_auth'    => true,
            'scope'            => (string) $link['scope'],
            'language'         => $this->service->clientLocale($link),
            'supplier_name'    => (string) (($supplier['display_name'] ?? '') ?: ($supplier['company_name'] ?? '')),
            'logo_src'         => $this->service->logoSrc($supplier),
            'masked_emails'    => $this->service->maskedEmails($link),
            'captcha_site_key' => (string) $this->config->get('captcha.site_key', ''),
            'captcha_provider' => (string) $this->config->get('captcha.provider', 'none'),
        ]);
    }
}
