<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ares;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * ARES REST lookup pro IČO.
 *
 * Endpoint: GET /ekonomicke-subjekty/{ico}
 * Cache: ares_cache 24h
 *
 * Vrací normalizovaný array nebo null pokud subjekt nenalezen / chyba sítě.
 */
final class AresClient
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{found:bool, data?:array<string,mixed>, source:'cache'|'fresh'}|null
     */
    public function lookup(string $ico): ?array
    {
        $ico = preg_replace('/\D/', '', $ico) ?? '';
        if (strlen($ico) !== 8) {
            return ['found' => false, 'source' => 'fresh'];
        }

        $cached = $this->fromCache($ico);
        if ($cached !== null) {
            $cached['source'] = 'cache';
            return $cached;
        }

        $base = rtrim((string) $this->config->get('ares.api'), '/');
        // Bez baseline URL bychom poslali Guzzle relativní path → cURL error 3
        // ("URL rejected: No host part in the URL"), což se historicky logovalo
        // jako "ARES API nedostupné" — uživatele to vysílá ladit síť místo configu.
        // Per issue #30: Config::baselineDefaults() už default poskytuje, ale
        // pokud admin v cfg.local.php / ENV nastaví prázdný string, chytneme to tady.
        if ($base === '') {
            $this->logger->warning('ARES URL není nakonfigurovaná (config.ares.api je prázdná) — lookup přeskočen', ['ico' => $ico]);
            return null;
        }
        $url  = $base . '/' . $ico;
        $timeout = (int) $this->config->get('ares.timeout', 5);

        try {
            $client = new Client(['timeout' => $timeout, 'connect_timeout' => $timeout]);
            $resp = $client->get($url, ['http_errors' => false, 'headers' => ['Accept' => 'application/json']]);
            $status = $resp->getStatusCode();

            if ($status === 404) {
                $payload = ['found' => false];
                $this->cache($ico, $payload);
                return $payload + ['source' => 'fresh'];
            }
            if ($status !== 200) {
                $this->logger->warning('ARES vrátilo neočekávaný status', ['ico' => $ico, 'status' => $status]);
                return null;
            }

            $body = json_decode((string) $resp->getBody(), true);
            if (!is_array($body)) {
                return null;
            }

            $normalized = $this->normalize($body);
            $payload = ['found' => true, 'data' => $normalized];
            $this->cache($ico, $payload);
            return $payload + ['source' => 'fresh'];
        } catch (GuzzleException $e) {
            $this->logger->warning('ARES API nedostupné: ' . $e->getMessage(), ['ico' => $ico]);
            return null;
        }
    }

    private function normalize(array $raw): array
    {
        $sidlo = $raw['sidlo'] ?? [];
        $regs  = $raw['seznamRegistraci'] ?? [];

        // Skládáme ulici z částí
        $street = trim((string) ($sidlo['nazevUlice'] ?? ''));
        $cisloDom = $sidlo['cisloDomovni'] ?? null;
        $cisloOr  = $sidlo['cisloOrientacni'] ?? null;
        if ($cisloDom !== null && $cisloOr !== null) {
            $street .= ' ' . $cisloDom . '/' . $cisloOr;
        } elseif ($cisloDom !== null) {
            $street .= ' ' . $cisloDom;
        }
        $street = trim($street);

        $psc = (string) ($sidlo['psc'] ?? '');
        if (preg_match('/^\d{5}$/', $psc)) {
            $psc = substr($psc, 0, 3) . ' ' . substr($psc, 3); // 30100 → "301 00"
        }

        return [
            'company_name' => (string) ($raw['obchodniJmeno'] ?? ''),
            'ic'           => (string) ($raw['ico'] ?? ''),
            'dic'          => (string) ($raw['dic'] ?? ''),
            'street'       => $street,
            'city'         => (string) ($sidlo['nazevObce'] ?? ''),
            'zip'          => $psc,
            'country_iso2' => (string) ($sidlo['kodStatu'] ?? 'CZ'),
            'is_vat_payer' => ($regs['stavZdrojeDph'] ?? '') === 'AKTIVNI',
            'date_active'  => (string) ($raw['datumVzniku'] ?? ''),
            'legal_form'   => (string) ($raw['pravniForma'] ?? ''),
        ];
    }

    private function fromCache(string $ico): ?array
    {
        $ttl = (int) $this->config->get('ares.cache_ttl', 86400);
        $stmt = $this->db->pdo()->prepare(
            'SELECT payload FROM ares_cache WHERE ic = ? AND fetched_at > NOW() - INTERVAL ? SECOND'
        );
        $stmt->execute([$ico, $ttl]);
        $row = $stmt->fetchColumn();
        if ($row === false) {
            return null;
        }
        $data = json_decode((string) $row, true);
        return is_array($data) ? $data : null;
    }

    private function cache(string $ico, array $payload): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ares_cache (ic, payload) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()'
        );
        $stmt->execute([$ico, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
}
