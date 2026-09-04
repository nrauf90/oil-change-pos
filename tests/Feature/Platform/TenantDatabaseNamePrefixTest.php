<?php

namespace Tests\Feature\Platform;

use App\Filament\Platform\Resources\Shops\Pages\CreateShop;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Shared-hosting control panels refuse to create a database whose name does not
 * begin with the account prefix, so the tenant database name must be
 * configurable. A hardcoded 'tenant_' makes provisioning impossible on cPanel.
 */
class TenantDatabaseNamePrefixTest extends TestCase
{
    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    private function databaseName(string $driver, string $slug): string
    {
        $method = new ReflectionMethod(CreateShop::class, 'databaseName');

        return $method->invoke(
            (new \ReflectionClass(CreateShop::class))->newInstanceWithoutConstructor(),
            $driver,
            $slug,
        );
    }

    #[Test]
    public function it_defaults_to_the_plain_tenant_convention(): void
    {
        config()->set('database.tenant_database_name_prefix', 'tenant_');

        $this->assertSame('tenant_voltera_garage', $this->databaseName('mysql', 'voltera-garage'));
    }

    #[Test]
    public function it_applies_a_configured_account_prefix(): void
    {
        config()->set('database.tenant_database_name_prefix', 'mnritsol_tenant_');

        $this->assertSame(
            'mnritsol_tenant_voltera_garage',
            $this->databaseName('mysql', 'voltera-garage'),
        );
    }

    #[Test]
    public function an_empty_prefix_falls_back_rather_than_producing_a_bare_slug(): void
    {
        config()->set('database.tenant_database_name_prefix', '');

        $this->assertSame('tenant_voltera_garage', $this->databaseName('mysql', 'voltera-garage'));
    }

    #[Test]
    public function sqlite_targets_are_files_and_ignore_the_prefix(): void
    {
        config()->set('database.tenant_database_name_prefix', 'mnritsol_tenant_');
        config()->set('database.tenant_sqlite_provisioning_root', '/tmp/tenants');

        $name = $this->databaseName('sqlite', 'voltera-garage');

        $this->assertStringEndsWith('voltera-garage.sqlite', $name);
        $this->assertStringNotContainsString('mnritsol_', $name);
    }
}
