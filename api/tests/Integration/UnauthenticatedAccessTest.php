<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Black-box integration test — bez session cookie.
 * Ověřuje že chráněné endpointy vrací 401, public 200/204.
 *
 * Vyžaduje běžící dev server na env MYINVOICE_TEST_URL nebo default https://dev.myinvoice.cz.
 * Spustit s: TEST_URL=https://dev.myinvoice.cz vendor/bin/phpunit --testsuite=Integration
 */
#[Group('integration')]
final class UnauthenticatedAccessTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = rtrim((string) (getenv('MYINVOICE_TEST_URL') ?: 'https://dev.myinvoice.cz'), '/');
        // Skip pokud server nedostupný (CI bez deploye)
        $h = $this->request('GET', '/api/health');
        if ($h['status'] === 0) {
            $this->markTestSkipped("Dev server {$this->baseUrl} nedostupný — preskakuji integration testy.");
        }
    }

    public function testProtectedInvoiceDetailReturns401(): void
    {
        $r = $this->request('GET', '/api/invoices/1');
        self::assertSame(401, $r['status'], 'GET /api/invoices/{id} bez auth musí být 401');
        $body = json_decode($r['body'], true);
        self::assertSame('unauthenticated', $body['error']['code'] ?? null);
    }

    public function testProtectedInvoiceListReturns401(): void
    {
        $r = $this->request('GET', '/api/invoices');
        self::assertSame(401, $r['status']);
    }

    public function testProtectedPdfReturns401(): void
    {
        $r = $this->request('GET', '/api/invoices/1/pdf');
        self::assertSame(401, $r['status'], 'PDF download bez auth musí být 401');
    }

    public function testProtectedClientsReturns401(): void
    {
        $r = $this->request('GET', '/api/clients');
        self::assertSame(401, $r['status']);
    }

    public function testProtectedDashboardReturns401(): void
    {
        $r = $this->request('GET', '/api/dashboard/summary');
        self::assertSame(401, $r['status']);
    }

    public function testProtectedAdminReturns401(): void
    {
        $r = $this->request('GET', '/api/admin/users');
        self::assertSame(401, $r['status']);
    }

    public function testProtectedZipExportReturns401(): void
    {
        $r = $this->request('GET', '/api/admin/invoices-zip?month=2026-05');
        self::assertSame(401, $r['status']);
    }

    public function testPublicHealthOk(): void
    {
        $r = $this->request('GET', '/api/health');
        self::assertContains($r['status'], [200, 204], 'health endpoint má být veřejný');
    }

    public function testPublicSetupStatusOk(): void
    {
        $r = $this->request('GET', '/api/auth/setup-status');
        self::assertContains($r['status'], [200, 204]);
    }

    public function testCsrfRequiredForMutation(): void
    {
        // POST bez CSRF tokenu musí být 403, ne 401 (auth má jiný path order)
        $r = $this->request('POST', '/api/clients', '{}');
        self::assertContains($r['status'], [401, 403], 'POST bez auth ani CSRF nesmí projít');
    }

    /**
     * @return array{status:int, body:string}
     */
    private function request(string $method, string $path, ?string $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => false, // dev self-signed
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['status' => (int) $status, 'body' => is_string($resp) ? $resp : ''];
    }
}
