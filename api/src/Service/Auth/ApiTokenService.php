<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * Personal Access Tokens pro veřejné REST API.
 *
 * Plaintext token: "mi_pat_" + 43 znaků base64url(random_bytes(32)).
 *   - V DB jen SHA-256 hash (`token_hash`).
 *   - Plaintext se vrací uživateli pouze jednou při `generate()`.
 *
 * Validace: SHA-256 lookup přes unique index. Konstantní-time srovnání není
 * potřeba — útočník nemůže iterovat 2^256 hashů.
 *
 * `touch()` aktualizuje `last_used_at` max 1× za 5 min (Redis throttle),
 * aby běžný traffic netloukl DB na každý request.
 */
final class ApiTokenService
{
    private const PLAINTEXT_PREFIX = 'mi_pat_';
    private const RANDOM_BYTES = 32;
    private const TOUCH_INTERVAL_SEC = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly RedisFactory $redis,
    ) {}

    /**
     * Vygeneruje nový token. Vrací plaintext (zobrazit uživateli jednou).
     *
     * @return array{plaintext: string, prefix: string, id: int}
     */
    public function generate(
        int $userId,
        ?int $supplierId,
        string $name,
        string $scope,
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        if (!in_array($scope, ['read', 'read_write'], true)) {
            throw new \InvalidArgumentException('Invalid scope: ' . $scope);
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Token name must be 1–100 chars');
        }

        $raw       = random_bytes(self::RANDOM_BYTES);
        $body      = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $plaintext = self::PLAINTEXT_PREFIX . $body;
        $hash      = hash('sha256', $plaintext);
        $prefix    = substr($plaintext, 0, 12);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO api_tokens (user_id, supplier_id, name, token_hash, prefix, scope, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $supplierId,
            $name,
            $hash,
            $prefix,
            $scope,
            $expiresAt?->format('Y-m-d H:i:s'),
        ]);

        return [
            'plaintext' => $plaintext,
            'prefix'    => $prefix,
            'id'        => (int) $this->db->pdo()->lastInsertId(),
        ];
    }

    /**
     * Ověří plaintext. Vrací řádek `api_tokens` rozšířený o `user_*` a `supplier_*`
     * nebo null, pokud token neexistuje, byl revokován nebo expiroval.
     */
    public function validate(string $plaintext): ?array
    {
        if (!str_starts_with($plaintext, self::PLAINTEXT_PREFIX)) {
            return null;
        }
        // Sanity check délky — odhadneme očekávanou délku, ať netáhneme nesmysly do DB.
        if (strlen($plaintext) < 20 || strlen($plaintext) > 80) {
            return null;
        }

        $hash = hash('sha256', $plaintext);

        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.user_id, t.supplier_id, t.name, t.prefix, t.scope,
                    t.expires_at, t.revoked_at,
                    u.email AS user_email, u.name AS user_name, u.role AS user_role,
                    u.locale AS user_locale, u.is_active AS user_is_active,
                    u.totp_enabled AS user_totp_enabled
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ?
               AND t.revoked_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at > NOW())
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if ((int) $row['user_is_active'] !== 1) {
            return null;
        }

        $row['id']                = (int) $row['id'];
        $row['user_id']           = (int) $row['user_id'];
        $row['supplier_id']       = $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null;
        $row['user_is_active']    = true;
        $row['user_totp_enabled'] = (int) ($row['user_totp_enabled'] ?? 0) === 1;
        return $row;
    }

    /**
     * Update last_used_at / last_used_ip. Throttle 5 min přes Redis;
     * při nedostupném Redisu updatuje pokaždé (vzácný edge-case).
     */
    public function touch(int $tokenId, string $ip): void
    {
        if (($r = $this->redis->client()) !== null) {
            $key = 'apitok:touch:' . $tokenId;
            // SET key 1 EX 300 NX — vrátí "OK" pokud nastaveno, null pokud klíč už existuje (= updateováno nedávno)
            $set = $r->set($key, '1', 'EX', self::TOUCH_INTERVAL_SEC, 'NX');
            if ($set === null) {
                return;
            }
        }

        $packed = @inet_pton($ip);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE api_tokens SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?'
        );
        $stmt->execute([$packed !== false ? $packed : null, $tokenId]);
    }

    /**
     * Vypíše tokeny daného usera (bez plaintextu).
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.supplier_id, s.display_name AS supplier_name, s.company_name AS supplier_company,
                    t.name, t.prefix, t.scope,
                    t.last_used_at, t.last_used_ip,
                    t.expires_at, t.revoked_at, t.created_at
             FROM api_tokens t
             LEFT JOIN supplier s ON s.id = t.supplier_id
             WHERE t.user_id = ?
             ORDER BY t.revoked_at IS NOT NULL, t.created_at DESC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']          = (int) $r['id'];
            $r['supplier_id'] = $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null;
            if ($r['last_used_ip'] !== null) {
                $r['last_used_ip'] = @inet_ntop($r['last_used_ip']) ?: null;
            }
            $r['is_revoked'] = $r['revoked_at'] !== null;
            $r['is_expired'] = $r['expires_at'] !== null && strtotime((string) $r['expires_at']) < time();
        }
        return $rows;
    }

    /**
     * Revokuje token. Idempotentní (opakované volání nezpůsobí chybu).
     * Bezpečnost: kontroluje, že token patří danému userovi.
     */
    public function revoke(int $tokenId, int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE api_tokens SET revoked_at = COALESCE(revoked_at, NOW())
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$tokenId, $userId]);
        return $stmt->rowCount() > 0;
    }
}
