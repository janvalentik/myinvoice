<?php

declare(strict_types=1);

/**
 * CLI: nouzově vypne 2FA uživateli podle e-mailu.
 *
 * Vypne TOTP (totp_enabled=0, totp_secret=NULL), zruší „zapamatovaná zařízení"
 * (trusted_devices) a smaže čekající e-mailové kódy (login_otps), aby byl reset
 * úplný i pro e-mailové OTP. Invaliduje všechny session uživatele.
 *
 * Použití:
 *   php api/bin/reset-2fa.php admin@example.com
 */

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tento skript musí běžet z CLI.\n");
    exit(1);
}

[$_, $email] = array_pad($argv, 2, null);
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Použití: php api/bin/reset-2fa.php <email>\n");
    exit(2);
}

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$sessions = $container->get(\MyInvoice\Service\Auth\SessionManager::class);

$stmt = $pdo->prepare('SELECT id, email, totp_enabled FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "User '$email' neexistuje.\n");
    exit(3);
}

$pdo->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?')
    ->execute([(int) $user['id']]);

// E-mailové 2FA: zruš důvěryhodná zařízení (vynutí znovuověření) a čekající kódy.
$td = $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?');
$td->execute([(int) $user['id']]);
$otp = $pdo->prepare('DELETE FROM login_otps WHERE user_id = ?');
$otp->execute([(int) $user['id']]);

$killed = $sessions->destroyAllForUser((int) $user['id']);
$wasEnabled = ((int) ($user['totp_enabled'] ?? 0) === 1) ? 'ano' : 'ne';

echo "✓ 2FA reset pro {$user['email']} (id={$user['id']}, TOTP původně aktivní: {$wasEnabled}).\n";
echo "  Zrušeno {$td->rowCount()} důvěryhodných zařízení, smazáno {$otp->rowCount()} e-mailových kódů, invalidováno $killed session(í).\n";
