<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\I18n\Locale;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SessionManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Načte session z cookie, pokud existuje, a vloží usera do request attributes.
 * Pokud route má atribut `requires_auth=true` a session není, vrátí 401.
 *
 * Public routes (login, forgot, reset, setup, health, csrf-token) jsou whitelisted.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const ATTR_USER       = 'auth.user';
    public const ATTR_SESSION    = 'auth.session';
    public const ATTR_TOKEN      = 'auth.token';

    private const PUBLIC_PATHS = [
        '/api/health',
        '/api/auth/setup-status',
        '/api/auth/setup',
        '/api/auth/setup-ares-lookup',
        '/api/auth/setup-sample',
        '/api/auth/login',
        '/api/auth/forgot',
        '/api/auth/reset',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly SessionManager $sessions,
        private readonly Connection $db,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        // Resolve locale per-request: user.locale > Accept-Language > default
        Locale::set(self::detectLocale($request->getHeaderLine('Accept-Language')));

        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $cookies    = $request->getCookieParams();
        $token      = (string) ($cookies[$cookieName] ?? '');

        $session = $token !== '' ? $this->sessions->load($token) : null;

        if ($session !== null) {
            // Načti aktivního usera
            $stmt = $this->db->pdo()->prepare('SELECT id, email, name, role, locale, is_active FROM users WHERE id = ?');
            $stmt->execute([$session['user_id']]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($user && (int) $user['is_active'] === 1) {
                $user['id']        = (int) $user['id'];
                $user['is_active'] = (bool) $user['is_active'];
                Locale::set((string) ($user['locale'] ?? 'cs'));
                $request = $request
                    ->withAttribute(self::ATTR_USER, $user)
                    ->withAttribute(self::ATTR_SESSION, $session)
                    ->withAttribute(self::ATTR_TOKEN, $token);

                $this->sessions->touch($token);
            } else {
                // User smazán/deaktivován — invaliduj session
                $this->sessions->destroy($token);
                $session = null;
            }
        }

        $path = $request->getUri()->getPath();
        if (in_array($path, self::PUBLIC_PATHS, true)
            || str_starts_with($path, '/api/public/')
        ) {
            return $handler->handle($request);
        }

        if ($request->getAttribute(self::ATTR_USER) === null) {
            $response = $this->responseFactory->createResponse(401);
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        return $handler->handle($request);
    }

    /**
     * RFC 7231 Accept-Language parser — vybere nejvíc preferovaný jazyk z whitelist (cs, en).
     * Příklad: "cs-CZ,cs;q=0.9,en;q=0.8" → 'cs'.
     * Při shodě q=1 vyhrává cs (default tržního prostoru).
     */
    private static function detectLocale(string $acceptLanguage): string
    {
        $best = ['lang' => 'cs', 'q' => 0.0];
        $hasCs = false;
        foreach (explode(',', strtolower($acceptLanguage)) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $q = 1.0;
            if (str_contains($part, ';')) {
                [$tag, $params] = array_pad(explode(';', $part, 2), 2, '');
                $tag = trim($tag);
                if (preg_match('/q\s*=\s*([0-9.]+)/', $params, $m)) {
                    $q = (float) $m[1];
                }
            } else {
                $tag = $part;
            }
            $primary = strtolower(explode('-', $tag, 2)[0]);
            if (!in_array($primary, ['cs', 'en'], true)) continue;
            if ($primary === 'cs') $hasCs = true;
            if ($q > $best['q']) $best = ['lang' => $primary, 'q' => $q];
        }
        // Tie-break: pokud má klient cs i en se stejnou q, preferuj cs
        if ($hasCs && $best['lang'] === 'en' && $best['q'] === 1.0) {
            return 'cs';
        }
        return $best['lang'];
    }
}
