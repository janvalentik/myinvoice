<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\EmailAuthenticationVerifier;
use PHPUnit\Framework\TestCase;

final class EmailAuthenticationVerifierTest extends TestCase
{
    private EmailAuthenticationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new EmailAuthenticationVerifier();
    }

    public function testNoHeaderIsNotChecked(): void
    {
        $r = $this->verifier->verify([], 'rb.cz');
        self::assertFalse($r['checked']);
        self::assertFalse($r['pass']);
    }

    public function testDmarcPassIsAccepted(): void
    {
        $r = $this->verifier->verify(['mx.tvujmail.cz; spf=pass dkim=pass dmarc=pass header.d=rb.cz'], 'rb.cz');
        self::assertTrue($r['pass']);
    }

    public function testDkimPassWithAlignedDomainIsAccepted(): void
    {
        $r = $this->verifier->verify(['mx.tvujmail.cz; dkim=pass header.d=rb.cz'], 'rb.cz');
        self::assertTrue($r['pass']);
    }

    public function testDkimPassWithSubdomainAligns(): void
    {
        $r = $this->verifier->verify(['mx.tvujmail.cz; dkim=pass header.d=mail.rb.cz'], 'rb.cz');
        self::assertTrue($r['pass']);
    }

    public function testDkimPassWithWrongDomainIsRejected(): void
    {
        $r = $this->verifier->verify(['mx.tvujmail.cz; dkim=pass header.d=evil.com'], 'rb.cz');
        self::assertTrue($r['checked']);
        self::assertFalse($r['pass']);
    }

    public function testDkimFailIsRejected(): void
    {
        $r = $this->verifier->verify(['mx.tvujmail.cz; spf=fail dkim=fail dmarc=fail'], 'rb.cz');
        self::assertFalse($r['pass']);
    }

    public function testForgedTopHeaderIgnoredWhenAuthServIdPinned(): void
    {
        // Útočníkem vložená hlavička navrchu předstírá pass, ale není od důvěryhodného serveru.
        $headers = [
            'attacker-injected; dkim=pass header.d=rb.cz',
            'mx.tvujmail.cz; dkim=fail dmarc=fail',
        ];
        $r = $this->verifier->verify($headers, 'rb.cz', 'mx.tvujmail.cz');
        self::assertFalse($r['pass'], 'Musí použít jen řádek s důvěryhodným authserv-id.');
    }

    public function testPinnedAuthServIdSelectsGenuineLine(): void
    {
        $headers = [
            'attacker-injected; dkim=fail',
            'mx.tvujmail.cz; dkim=pass header.d=rb.cz',
        ];
        $r = $this->verifier->verify($headers, 'rb.cz', 'mx.tvujmail.cz');
        self::assertTrue($r['pass']);
    }

    public function testDomainFromSender(): void
    {
        self::assertSame('rb.cz', $this->verifier->domainFromSender('Raiffeisenbank <info@rb.cz>'));
        self::assertSame('rb.cz', $this->verifier->domainFromSender('info@rb.cz'));
        self::assertNull($this->verifier->domainFromSender('not-an-email'));
    }
}
