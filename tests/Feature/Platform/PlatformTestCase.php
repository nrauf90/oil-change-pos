<?php

namespace Tests\Feature\Platform;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

abstract class PlatformTestCase extends TestCase
{
    private ?string $centralDatabasePath = null;

    private ?string $tenantDatabaseRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDatabaseRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'platform-tenants-'.Str::uuid();

        if (! File::makeDirectory($this->tenantDatabaseRoot, 0700, true)) {
            throw new RuntimeException('Unable to create a temporary tenant database directory.');
        }

        $centralDatabasePath = tempnam(sys_get_temp_dir(), 'platform-central-');

        if ($centralDatabasePath === false) {
            throw new RuntimeException('Unable to create a temporary central database.');
        }

        $this->centralDatabasePath = $centralDatabasePath;

        config()->set('database.tenant_sqlite_root', $this->tenantDatabaseRoot);
        config()->set('database.tenant_sqlite_provisioning_root', $this->tenantDatabaseRoot);
        config()->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => $this->centralDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'transaction_mode' => 'DEFERRED',
        ]);

        Auth::forgetGuards();
        DB::purge('central');

        Artisan::call('migrate', [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--realpath' => false,
            '--no-interaction' => true,
        ]);

        $platformPanel = Filament::getPanels()['platform'] ?? null;

        if ($platformPanel !== null) {
            Filament::setCurrentPanel($platformPanel);
        }
    }

    protected function tearDown(): void
    {
        try {
            Auth::forgetGuards();
            DB::purge('central');

            if ($this->centralDatabasePath !== null) {
                File::delete($this->centralDatabasePath);
            }

            if ($this->tenantDatabaseRoot !== null) {
                File::deleteDirectory($this->tenantDatabaseRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    protected function centralDatabasePath(): string
    {
        if ($this->centralDatabasePath === null) {
            throw new RuntimeException('The temporary central database is not configured.');
        }

        return $this->centralDatabasePath;
    }
}
