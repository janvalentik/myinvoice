<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\Json;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Captcha\TurnstileVerifier;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\WorkReport\WorkReportLinkService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/public/work-report/{token}/request-code
 *
 * Body: { email, cf_turnstile_response?, resend? }
 *
 * Pošle 6místný ověřovací kód na e-mail (jen je-li mezi povolenými adresami).
 * Odpověď je VŽDY generická ({ ok:true }) — proti enumeraci adres.
 */
final class PublicWorkReportRequestCodeAction
{
    public function __construct(
        private readonly WorkReportLinkService $service,
        private readonly TurnstileVerifier $captcha,
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

        $body = (array) ($request->getParsedBody() ?? []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        $captchaToken = (string) ($body['cf_turnstile_response'] ?? '');
        if (!$this->captcha->verify($captchaToken, $ip, 'work_report')) {
            return Json::error($response, 'captcha_failed',
                'Ověření CAPTCHA selhalo, zkuste to prosím znovu.', 422);
        }

        $email = trim((string) ($body['email'] ?? ''));
        $force = !empty($body['resend']);

        $cooldown = 0;
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result = $this->service->issueCode($link, $email, $ip, $force);
            $cooldown = (int) $result['cooldown_remaining'];
            if ($result['sent']) {
                $this->logger->log('work_report_link.code_sent', null, 'work_report_link', (int) $link['id'], [
                    'email_masked' => WorkReportLinkService::maskEmail(mb_strtolower($email)),
                ], $ip, $request->getHeaderLine('User-Agent'));
            }
        }

        // Generická odpověď bez ohledu na to, zda byla adresa povolená.
        return Json::ok($response, ['ok' => true, 'cooldown_remaining' => $cooldown]);
    }
}
