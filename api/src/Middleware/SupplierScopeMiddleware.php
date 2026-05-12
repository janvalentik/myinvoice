<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Infrastructure\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Multi-supplier scope: čte hlavičku `X-Supplier-Id` (z Pinia stores na FE) a
 * vystaví ji jako request attribute. Akce čtou přes:
 *
 *   $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
 *
 * Pravidla:
 *   - Pokud header chybí nebo není v DB, fallback = MIN(supplier.id) (= "default supplier")
 *   - Pokud supplier tabulka prázdná (před setup) → 0 (akce by stejně měly být chráněné Authem)
 *   - Validace existence se cachuje v rámci request (jeden DB hit)
 */
final class SupplierScopeMiddleware implements MiddlewareInterface
{
    public const ATTR_CURRENT_ID = 'supplier.current_id';
    public const HEADER_NAME     = 'X-Supplier-Id';

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        // 0. Bearer (API token) — pokud je token bound na konkrétního supplier-a,
        //    forcuj ho a ignoruj header / query (token nesmí "skočit" do jiné firmy).
        $apiToken = $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN);
        if (is_array($apiToken) && ($apiToken['supplier_id'] ?? null) !== null) {
            return $handler->handle(
                $request->withAttribute(self::ATTR_CURRENT_ID, (int) $apiToken['supplier_id']),
            );
        }

        // 1. Header X-Supplier-Id (axios v SPA)
        $headerVal = trim($request->getHeaderLine(self::HEADER_NAME));
        $requested = ctype_digit($headerVal) ? (int) $headerVal : 0;

        // 2. Fallback: query param ?supplier_id=N (přímá navigace v prohlížeči — PDF download, ZIP export apod.)
        if ($requested === 0) {
            $q = $request->getQueryParams();
            $qVal = isset($q['supplier_id']) ? trim((string) $q['supplier_id']) : '';
            if (ctype_digit($qVal)) {
                $requested = (int) $qVal;
            }
        }

        $resolved = $this->resolve($requested);

        return $handler->handle(
            $request->withAttribute(self::ATTR_CURRENT_ID, $resolved),
        );
    }

    /**
     * Vrátí platné supplier_id:
     *  - $requested pokud existuje v DB
     *  - jinak MIN(id)
     *  - jinak 0 (před setup)
     */
    private function resolve(int $requested): int
    {
        $pdo = $this->db->pdo();

        if ($requested > 0) {
            $stmt = $pdo->prepare('SELECT id FROM supplier WHERE id = ? LIMIT 1');
            $stmt->execute([$requested]);
            $id = (int) $stmt->fetchColumn();
            if ($id > 0) return $id;
        }

        return (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
    }
}
