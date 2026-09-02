<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlatformBootstrapTest extends TestCase
{
    private ?string $centralDatabasePath = null;

    private ?string $temporaryDirectory = null;

    protected function tearDown(): void
    {
        try {
            DB::purge('central');

            if ($this->centralDatabasePath !== null) {
                (new Filesystem)->delete($this->centralDatabasePath);
            }

            if ($this->temporaryDirectory !== null) {
                (new Filesystem)->deleteDirectory($this->temporaryDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    public function test_fresh_setup_creates_the_default_sqlite_database_before_running_central_migrations_without_seeding(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $scripts = $composer['scripts'];
        $setupCommands = $scripts['setup'];
        $databaseCreationIndex = array_key_first(array_filter(
            $setupCommands,
            static fn (string $command): bool => str_contains($command, "touch('database/database.sqlite')"),
        ));
        $migrationIndex = array_key_first(array_filter(
            $setupCommands,
            static fn (string $command): bool => str_contains($command, 'artisan migrate'),
        ));
        $postCreateMigrationCommand = collect($scripts['post-create-project-cmd'])
            ->first(static fn (string $command): bool => str_contains($command, 'artisan migrate'));

        $this->assertIsInt($databaseCreationIndex);
        $this->assertIsInt($migrationIndex);
        $this->assertLessThan($migrationIndex, $databaseCreationIndex);
        $this->assertIsString($postCreateMigrationCommand);
        $this->assertStringContainsString('--database=central', $postCreateMigrationCommand);
        $this->assertStringContainsString('--path=database/migrations/central', $postCreateMigrationCommand);

        foreach (['setup', 'post-create-project-cmd'] as $scriptName) {
            foreach ($scripts[$scriptName] as $command) {
                $this->assertStringNotContainsString('db:seed', $command);
                $this->assertStringNotContainsString('--seed', $command);
                $this->assertStringNotContainsString('--pretend', $command);
            }
        }

        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'platform-bootstrap-'.str()->uuid();
        (new Filesystem)->ensureDirectoryExists($this->temporaryDirectory.DIRECTORY_SEPARATOR.'database', 0700);
        $this->centralDatabasePath = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite';

        $creationProcess = $this->runComposerPhpCommand(
            $setupCommands[$databaseCreationIndex],
            $this->temporaryDirectory,
        );

        $this->assertSame(0, $creationProcess->getExitCode(), $creationProcess->getErrorOutput());
        $this->assertFileExists($this->centralDatabasePath);

        $migrationProcess = $this->runComposerPhpCommand(
            $setupCommands[$migrationIndex],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'CACHE_STORE' => 'array',
                'CENTRAL_DB_CONNECTION' => 'sqlite',
                'CENTRAL_DB_DATABASE' => $this->centralDatabasePath,
                'DB_CONNECTION' => 'central',
                'DB_DATABASE' => $this->centralDatabasePath,
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ],
        );

        $this->assertSame(0, $migrationProcess->getExitCode(), $migrationProcess->getErrorOutput());

        config()->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => $this->centralDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('central');

        $this->assertTrue(Schema::connection('central')->hasTable('platform_users'));
        $this->assertTrue(Schema::connection('central')->hasTable('shops'));
    }

    /** @param array<string, string> $environment */
    private function runComposerPhpCommand(string $command, string $workingDirectory, array $environment = []): Process
    {
        $command = preg_replace('/\\A@php /', '"'.PHP_BINARY.'" ', $command);

        if (! is_string($command)) {
            throw new RuntimeException('The Composer command could not be prepared.');
        }

        $process = Process::fromShellCommandline($command, $workingDirectory, $environment);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }
}
