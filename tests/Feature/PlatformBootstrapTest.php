<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PlatformBootstrapTest extends TestCase
{
    private ?string $centralDatabasePath = null;

    protected function tearDown(): void
    {
        try {
            DB::purge('central');

            if ($this->centralDatabasePath !== null) {
                (new Filesystem)->delete($this->centralDatabasePath);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    public function test_fresh_setup_scripts_target_central_migrations_without_seeding_tenant_data(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $scripts = $composer['scripts'];

        foreach (['setup', 'post-create-project-cmd'] as $scriptName) {
            $migrationCommand = collect($scripts[$scriptName])
                ->first(static fn (string $command): bool => str_contains($command, 'artisan migrate'));

            $this->assertIsString($migrationCommand);
            $this->assertStringContainsString('--database=central', $migrationCommand);
            $this->assertStringContainsString('--path=database/migrations/central', $migrationCommand);
            $this->assertStringNotContainsString('--seed', implode(' ', $scripts[$scriptName]));
        }
    }

    public function test_the_documented_central_migration_command_creates_platform_tables(): void
    {
        $this->centralDatabasePath = tempnam(sys_get_temp_dir(), 'bootstrap-central-');

        if ($this->centralDatabasePath === false) {
            throw new RuntimeException('Unable to create a temporary central database.');
        }

        config()->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => $this->centralDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('central');

        $exitCode = Artisan::call('migrate', [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue(Schema::connection('central')->hasTable('platform_users'));
        $this->assertTrue(Schema::connection('central')->hasTable('shops'));
    }
}
