<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AnthropicClient;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pure-logic testy pro privátní helpery v AnthropicClientu.
 *
 * Pokrývá buildTenantContextBlock — dynamickou hlavičku promptu, která říká AI
 * že tenant je vždy odběratel, nikdy dodavatel. Bez tohoto bloku AI občas
 * zamíchá vendor↔customer u faktur s dominantní hlavičkou dodavatele.
 */
#[AllowMockObjectsWithoutExpectations]
final class AnthropicClientUnitTest extends TestCase
{
    public function testTenantBlock_full_info_includes_name_ic_dic(): void
    {
        $client = $this->makeClient([
            'company_name' => 'MyWebdesign.cz s.r.o.',
            'ic'           => '21370362',
            'dic'          => 'CZ21370362',
        ]);

        $block = $this->invokeBuild($client, 1);
        $this->assertNotEmpty($block);
        $this->assertStringContainsString('MyWebdesign.cz s.r.o.', $block);
        $this->assertStringContainsString('21370362', $block);
        $this->assertStringContainsString('CZ21370362', $block);
        // Klíčové sdělení: tenant je VŽDY odběratel
        $this->assertStringContainsString('VŽDY odběratel', $block);
        $this->assertStringContainsString('NIKDY', $block);
    }

    public function testTenantBlock_only_name_omits_ic_dic(): void
    {
        // Když supplier nemá IČ/DIČ, hint sentence obsahuje jen `název "..."`,
        // BEZ položek `IČO "..."` a `DIČ "..."`. Pozor: statický text promptu
        // pořád obsahuje slovo "IČO" v instrukci "matchuj IČO nebo název" —
        // proto kontrolujeme jen formátování citace s uvozovkou (`IČO "`).
        $client = $this->makeClient([
            'company_name' => 'OSVČ bez identifikace',
            'ic'           => null,
            'dic'          => null,
        ]);

        $block = $this->invokeBuild($client, 1);
        $this->assertStringContainsString('OSVČ bez identifikace', $block);
        $this->assertStringContainsString('pro firmu: název "OSVČ bez identifikace"', $block);
        $this->assertStringNotContainsString('IČO "', $block);
        $this->assertStringNotContainsString('DIČ "', $block);
    }

    public function testTenantBlock_empty_supplier_returns_empty_string(): void
    {
        $client = $this->makeClient([
            'company_name' => '',
            'ic'           => '',
            'dic'          => '',
        ]);
        $this->assertSame('', $this->invokeBuild($client, 1));
    }

    public function testTenantBlock_supplier_not_found_returns_empty_string(): void
    {
        $client = $this->makeClient(false); // fetch vrátí false = řádek neexistuje
        $this->assertSame('', $this->invokeBuild($client, 999));
    }

    public function testTenantBlock_db_error_returns_empty_string_no_exception(): void
    {
        // Když Connection->pdo() hodí, buildTenantContextBlock to musí
        // tiše ošetřit (prompt se vrátí bez tenant hlavičky, AI extrakce
        // proběhne bez kontextu, ale ne kompletně selže).
        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willThrowException(new \RuntimeException('DB down'));

        $client = new AnthropicClient(
            $conn,
            $this->createMock(SecretEncryption::class),
            new NullLogger(),
        );
        $this->assertSame('', $this->invokeBuild($client, 1));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|false $supplierRow Mock řádek z supplier tabulky,
     *                                               nebo false (fetch nic nenašel)
     */
    private function makeClient(array|false $supplierRow): AnthropicClient
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($supplierRow);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willReturn($pdo);

        return new AnthropicClient(
            $conn,
            $this->createMock(SecretEncryption::class),
            new NullLogger(),
        );
    }

    private function invokeBuild(AnthropicClient $client, int $supplierId): string
    {
        $ref = new \ReflectionMethod($client, 'buildTenantContextBlock');
        return (string) $ref->invoke($client, $supplierId);
    }
}
