<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

/**
 * Možnost A ověření autenticity e-mailu: NEpřepočítáváme DKIM/SPF sami (to dělá
 * přijímací server při doručení), jen důvěřujeme jeho verdiktu z hlavičky
 * `Authentication-Results` a vyžadujeme `dmarc=pass` nebo `dkim=pass` s doménou
 * zarovnanou na odesílatele.
 *
 * Bezpečnostní pozn.: hlavičku A-R umí kdokoli vložit do těla zprávy. Důvěryhodná
 * je jen ta, kterou přidal náš přijímací server (prepend → index 0). Pokud je
 * nakonfigurován `trustedAuthServId`, hledáme jen řádek s tímto authserv-id;
 * jinak bereme nejvyšší (= poslední hop).
 */
final class EmailAuthenticationVerifier
{
    /**
     * @param list<string> $authResults
     * @return array{checked:bool, pass:bool, detail:string}
     */
    public function verify(array $authResults, ?string $expectedDomain, ?string $trustedAuthServId = null): array
    {
        if ($authResults === []) {
            return ['checked' => false, 'pass' => false, 'detail' => 'no_authentication_results'];
        }

        $line = null;
        $trustedAuthServId = $trustedAuthServId !== null ? trim($trustedAuthServId) : '';
        if ($trustedAuthServId !== '') {
            foreach ($authResults as $candidate) {
                $servId = trim(explode(';', $candidate, 2)[0]);
                if (stripos($servId, $trustedAuthServId) !== false) {
                    $line = $candidate;
                    break;
                }
            }
            if ($line === null) {
                return ['checked' => true, 'pass' => false, 'detail' => 'authserv_id_mismatch'];
            }
        } else {
            // Bez připnutí důvěřujeme nejvyšší hlavičce (přidal ji přijímací server).
            $line = $authResults[0];
        }

        $lc = strtolower($line);

        // dmarc=pass implikuje zarovnání na From doménu už na přijímacím serveru → silné samo o sobě.
        if (preg_match('/\bdmarc\s*=\s*pass\b/', $lc) === 1) {
            return ['checked' => true, 'pass' => true, 'detail' => 'dmarc_pass'];
        }

        // dkim=pass musí mít doménu (header.d=) zarovnanou na očekávaného odesílatele.
        if (preg_match_all('/\bdkim\s*=\s*pass\b([^;]*)/', $lc, $matches) >= 1) {
            foreach ($matches[1] as $rest) {
                if ($expectedDomain === null || $expectedDomain === '') {
                    return ['checked' => true, 'pass' => true, 'detail' => 'dkim_pass'];
                }
                if (preg_match('/header\.d\s*=\s*([a-z0-9.\-]+)/', $rest, $d) === 1
                    && $this->domainAligns($d[1], $expectedDomain)) {
                    return ['checked' => true, 'pass' => true, 'detail' => 'dkim_pass_aligned'];
                }
            }
            return ['checked' => true, 'pass' => false, 'detail' => 'dkim_domain_mismatch'];
        }

        return ['checked' => true, 'pass' => false, 'detail' => 'auth_failed'];
    }

    /**
     * Vytáhne doménu z From adresy ("Jméno <info@rb.cz>" i "info@rb.cz").
     */
    public function domainFromSender(string $sender): ?string
    {
        if (preg_match('/[<\s]?([^<>@\s]+)@([a-z0-9.\-]+)/i', $sender, $m) === 1) {
            return strtolower($m[2]);
        }
        return null;
    }

    private function domainAligns(string $domain, string $expected): bool
    {
        $domain = strtolower(ltrim($domain, '.'));
        $expected = strtolower(ltrim($expected, '.'));
        if ($domain === '' || $expected === '') {
            return false;
        }
        // Relaxed alignment: shoda nebo subdoména kteréhokoli směru (rb.cz ~ mail.rb.cz).
        return $domain === $expected
            || str_ends_with($domain, '.' . $expected)
            || str_ends_with($expected, '.' . $domain);
    }
}
