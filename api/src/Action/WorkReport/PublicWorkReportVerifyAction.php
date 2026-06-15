<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\WorkReport\WorkReportLinkService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/public/work-report/{token}/verify
 *
 * Body: { email, code }
 *
 * Ověří jednorázový kód. Při úspěchu nastaví dlouhodobou httpOnly cookie
 * (Path scoped na konkrétní odkaz) a vrátí živý náhled.
 */
final class PublicWorkReportVerifyAction
{
    public function __construct(
        private readonly WorkReportLinkService $service,
        private readonly Config $config,
        private readonly IpMatcher $ipMatcher,
        private readonly ActivityLogger $logger,
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

        $body  = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($body['email'] ?? ''));
        $code  = trim((string) ($body['code'] ?? ''));
        $ip    = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        $sessionToken = $this->service->verifyCode($link, $email, $code, $ip);
        if ($sessionToken === null) {
            return Json::error($response, 'invalid_code', 'Neplatný nebo expirovaný kód.', 422);
        }

        $this->logger->log('work_report_link.verified', null, 'work_report_link', (int) $link['id'], [
            'email_masked' => WorkReportLinkService::maskEmail(mb_strtolower($email)),
        ], $ip, $request->getHeaderLine('User-Agent'));

        $this->service->touchViewed((int) $link['id']);

        $secure   = (bool) $this->config->get('session.cookie_secure', true);
        $sameSite = (string) $this->config->get('session.cookie_samesite', 'Lax');
        $maxAge   = $this->service->sessionLifetimeDays() * 86400;
        $cookie = sprintf(
            '%s=%s; HttpOnly; Path=/api/public/work-report/%s; Max-Age=%d; SameSite=%s%s',
            WorkReportLinkService::COOKIE_NAME,
            $sessionToken,
            $token,
            $maxAge,
            $sameSite,
            $secure ? '; Secure' : '',
        );

        return Json::ok($response, [
            'ok'      => true,
            'preview' => $this->service->buildPreview($link),
        ])->withHeader('Set-Cookie', $cookie);
    }
}
