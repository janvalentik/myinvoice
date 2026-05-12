<?php

declare(strict_types=1);

/**
 * cmd/check-openapi-coverage.php
 *
 * Audit drift mezi Slim routes (api/src/Routes.php) a api/openapi.yaml.
 * Reportuje:
 *   - routes v kódu, které nejsou dokumentované → riziko, že je integrátoři minou
 *   - paths v openapi.yaml, které už v kódu neexistují → mrtvá dokumentace
 *
 * Záměrně ignoruje:
 *   - /api/admin/* — interní endpointy, plán je nedokumentovat
 *   - /api/auth/setup*, /api/auth/login, /logout, /forgot, /reset, /change-password,
 *     /totp/*, /me — UI/wizard scope, integrace přes bearer je neřeší
 *   - /api/public/approval/* — pro koncové zákazníky, ne pro integrace
 *   - /api/openapi.yaml, /api/docs, /api/health, /api/version — self-reference / triviální
 *   - mutace na /api/settings/*, /api/suppliers (POST/PUT/DELETE) a /api/admin/update/*
 *
 * Exit kódy:
 *   0 = bez nálezů
 *   1 = mismatch nalezen (CI warning, ne fail)
 */

$root = dirname(__DIR__);
require $root . '/api/vendor/autoload.php';

$routesFile  = $root . '/api/src/Routes.php';
$openapiFile = $root . '/api/openapi.yaml';

if (!is_file($routesFile))  { fwrite(STDERR, "ERR: missing $routesFile\n"); exit(2); }
if (!is_file($openapiFile)) { fwrite(STDERR, "ERR: missing $openapiFile\n"); exit(2); }

// --- 1) Extract routes z Routes.php ----------------------------------------
$src = (string) file_get_contents($routesFile);
$routes = [];

// Plain $app-> calls
if (preg_match_all(
    '/\$app->(get|post|put|patch|delete|any)\s*\(\s*[\'"]([^\'"]+)[\'"]/i',
    $src,
    $m,
    PREG_SET_ORDER
)) {
    foreach ($m as $hit) {
        $routes[] = ['method' => strtoupper($hit[1]), 'path' => $hit[2]];
    }
}

// Group-routes ($g-> uvnitř $app->group('/api/auth', ...))
if (preg_match_all(
    '/\$app->group\s*\(\s*[\'"]([^\'"]+)[\'"]/i',
    $src,
    $gm,
    PREG_SET_ORDER
)) {
    foreach ($gm as $gp) {
        $prefix = $gp[1];
        if (preg_match_all(
            '/\$g->(get|post|put|patch|delete|any)\s*\(\s*[\'"]([^\'"]+)[\'"]/i',
            $src,
            $im,
            PREG_SET_ORDER
        )) {
            foreach ($im as $hit) {
                $routes[] = ['method' => strtoupper($hit[1]), 'path' => $prefix . $hit[2]];
            }
        }
    }
}

// Normalize: drop placeholder regex (`{id:[0-9]+}` → `{id}`)
foreach ($routes as &$r) {
    $r['path'] = preg_replace('/:\\[[^\\]]+\\][^}]*/', '', $r['path']);
    // Slim path /:id → OpenAPI {id} (žádný case tady, ale safety)
}
unset($r);

// --- 2) Endpoints, které vědomě neaudituji ---------------------------------
$skipPrefixes = [
    '/api/admin/',
    '/api/public/',
    '/api/auth/setup',
    '/api/auth/login',
    '/api/auth/logout',
    '/api/auth/me',
    '/api/auth/forgot',
    '/api/auth/reset',
    '/api/auth/change-password',
    '/api/auth/totp/',
    '/api/auth/tokens',            // session-only, nelze volat bearer-em
    '/api/settings/email-branding/', // admin UI tooling (logo upload, preview)
];
$skipExact = [
    '/api/openapi.yaml',
    '/api/docs',
    '/api/reference',
    '/api/health',          // dokumentované, ale alias /api/v1/health
    '/api/version',
    '/api/invoices/preview-varsymbol', // admin tooling
    '/api/invoices/{id}/send-test',
    '/api/invoices/{id}/reminder-test',
    '/api/invoices/{id}/request-approval-test',
    '/api/{path}',  // catch-all 404 fallback
];

$shouldSkip = function (string $path) use ($skipPrefixes, $skipExact): bool {
    foreach ($skipPrefixes as $p) if (str_starts_with($path, $p)) return true;
    return in_array($path, $skipExact, true);
};

// Settings/suppliers mutace (POST/PUT/DELETE) — záměrně mimo public API
$isSettingsMutation = function (string $method, string $path): bool {
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        if (preg_match('#^/api/(settings|suppliers)(/|$)#', $path)) return true;
    }
    return false;
};

// --- 3) Načti openapi.yaml -------------------------------------------------
$yaml = (string) file_get_contents($openapiFile);
// Mini parser: nepoužíváme Symfony Yaml (není v deps), stačí grep paths
preg_match_all('/^  (\/api\/v1\/[^:]+):/m', $yaml, $pm);
$specPaths = [];
foreach ($pm[1] as $p) {
    // Strip /api/v1 prefix → "/api/..." (aby šlo porovnat s routes)
    $normalized = '/api' . substr($p, strlen('/api/v1'));
    $specPaths[] = $normalized;
}

// Pro každý path zjistíme, jaké metody jsou v něm definované
$specByPath = [];
$lines = explode("\n", $yaml);
$currentPath = null;
foreach ($lines as $line) {
    if (preg_match('/^  (\/api\/v1\/[^:]+):/', $line, $m)) {
        $currentPath = '/api' . substr($m[1], strlen('/api/v1'));
        $specByPath[$currentPath] = [];
        continue;
    }
    if ($currentPath !== null && preg_match('/^    (get|post|put|patch|delete):/i', $line, $m)) {
        $specByPath[$currentPath][] = strtoupper($m[1]);
    }
    // Reset když narazíme na další top-level klíč (1 mezera nebo žádná)
    if (preg_match('/^[a-z]/', $line)) {
        $currentPath = null;
    }
}

// --- 4) Porovnání ---------------------------------------------------------
$missingInSpec = []; // route v kódu, chybí v specu
$staleInSpec   = []; // path v specu, chybí v kódu

foreach ($routes as $r) {
    if ($shouldSkip($r['path']))                     continue;
    if ($isSettingsMutation($r['method'], $r['path'])) continue;
    if ($r['method'] === 'ANY')                      continue; // 404 fallback

    $found = isset($specByPath[$r['path']]) && in_array($r['method'], $specByPath[$r['path']], true);
    if (!$found) {
        $missingInSpec[] = $r['method'] . ' ' . $r['path'];
    }
}

// Routes existing in code (any method), indexed by path
$codeByPath = [];
foreach ($routes as $r) {
    $codeByPath[$r['path']][] = $r['method'];
}
foreach ($specByPath as $path => $methods) {
    if (!isset($codeByPath[$path])) {
        $staleInSpec[] = '(no methods) ' . $path;
        continue;
    }
    foreach ($methods as $method) {
        if (!in_array($method, $codeByPath[$path], true)) {
            $staleInSpec[] = $method . ' ' . $path;
        }
    }
}

// --- 5) Report -------------------------------------------------------------
$has = static fn (array $a) => count($a) > 0;
$pad = static fn (string $s, int $n) => str_pad($s, $n);

echo "OpenAPI ↔ routes coverage\n";
echo "==========================\n";
echo "Routes scanned (after filters): " . count(array_filter(
    $routes,
    static fn ($r) => !$shouldSkip($r['path']) && !$isSettingsMutation($r['method'], $r['path']) && $r['method'] !== 'ANY'
)) . "\n";
echo "Spec paths: " . count($specPaths) . " (each may have multiple methods)\n\n";

if (!$has($missingInSpec) && !$has($staleInSpec)) {
    echo "✓ No drift.\n";
    exit(0);
}

if ($has($missingInSpec)) {
    echo "Missing in openapi.yaml (" . count($missingInSpec) . "):\n";
    foreach ($missingInSpec as $row) echo "  - $row\n";
    echo "\n";
}
if ($has($staleInSpec)) {
    echo "Stale in openapi.yaml — not in code (" . count($staleInSpec) . "):\n";
    foreach ($staleInSpec as $row) echo "  - $row\n";
    echo "\n";
}

echo "Exit 1 — drift detected (warning only, CI nezablokuje).\n";
exit(1);
