<?php

namespace Tests\Feature\Tenancy;

use App\Actions\ManageTenantUsers;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TenantAdministratorConcurrencyTest extends TestCase
{
    public function test_management_action_updates_normally_but_reauthorizes_a_stale_actor(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->manager()->create(['name' => 'Original Name']);
        $updated = resolve(ManageTenantUsers::class)->update(
            $actor,
            $target,
            ['name' => 'Updated Name', 'is_active' => false],
            Role::Technician->value,
        );

        $this->assertSame('Updated Name', $updated->name);
        $this->assertFalse($updated->is_active);
        $this->assertSame([Role::Technician->value], $updated->roles->pluck('name')->all());

        $untouched = User::factory()->manager()->create(['name' => 'Untouched']);
        $page = Livewire::actingAs($actor)
            ->test(EditUser::class, ['record' => $untouched->getKey()])
            ->fillForm(['name' => 'Blocked Rename']);

        DB::connection('tenant')->table('users')
            ->where('id', $actor->getKey())
            ->update(['is_active' => false]);

        try {
            $page->call('save');
        } catch (AuthorizationException) {
        }

        $this->assertSame('Untouched', $untouched->fresh()->name);
    }

    public function test_overlapping_admin_demotions_never_remove_every_active_admin(): void
    {
        $actor = $this->authorizedManager();
        $firstAdmin = User::factory()->admin()->create();
        $secondAdmin = User::factory()->admin()->create();

        [$firstResult, $secondResult, $secondReadBeforeRelease] = $this->runRace(
            actor: $actor,
            firstTarget: $firstAdmin,
            firstOperation: 'demote',
            secondTarget: $secondAdmin,
            secondOperation: 'demote',
        );

        $this->assertFalse($secondReadBeforeRelease, 'The second mutation read stale administrator state.');
        $this->assertSame('ok', $firstResult['status']);
        $this->assertSame('rejected', $secondResult['status']);
        $this->assertTrue($firstAdmin->fresh()->isManager());
        $this->assertTrue($secondAdmin->fresh()->isAdmin());
        $this->assertSame(1, $this->activeAdministratorCount());
    }

    public function test_overlapping_delete_and_deactivation_never_remove_every_active_admin(): void
    {
        $actor = $this->authorizedManager();
        $deletedAdmin = User::factory()->admin()->create();
        $remainingAdmin = User::factory()->admin()->create();

        [$firstResult, $secondResult, $secondReadBeforeRelease] = $this->runRace(
            actor: $actor,
            firstTarget: $deletedAdmin,
            firstOperation: 'delete',
            secondTarget: $remainingAdmin,
            secondOperation: 'deactivate',
        );

        $this->assertFalse($secondReadBeforeRelease, 'The second mutation read stale administrator state.');
        $this->assertSame('ok', $firstResult['status']);
        $this->assertSame('rejected', $secondResult['status']);
        $this->assertModelMissing($deletedAdmin);
        $this->assertTrue($remainingAdmin->fresh()->is_active);
        $this->assertSame(1, $this->activeAdministratorCount());
    }

    private function authorizedManager(): User
    {
        $actor = User::factory()->manager()->create();
        $actor->givePermissionTo(
            Permission::UpdateUser->value,
            Permission::DeleteUser->value,
        );

        return $actor;
    }

    /**
     * @return array{
     *     array{status: string, exception?: string, message?: string},
     *     array{status: string, exception?: string, message?: string},
     *     bool
     * }
     */
    private function runRace(
        User $actor,
        User $firstTarget,
        string $firstOperation,
        User $secondTarget,
        string $secondOperation,
    ): array {
        DB::connection('tenant')->statement('PRAGMA journal_mode = WAL');
        DB::connection('tenant')->statement('PRAGMA busy_timeout = 5000');

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'tenant-administrator-concurrency-'.Str::uuid();
        File::ensureDirectoryExists($directory, 0700);
        $markers = [
            'first_read' => $directory.DIRECTORY_SEPARATOR.'first-read',
            'release_first' => $directory.DIRECTORY_SEPARATOR.'release-first',
            'second_attempting' => $directory.DIRECTORY_SEPARATOR.'second-attempting',
            'second_read' => $directory.DIRECTORY_SEPARATOR.'second-read',
        ];
        $firstProcess = $this->mutationProcess(
            (int) $actor->getKey(),
            (int) $firstTarget->getKey(),
            $firstOperation,
            'first',
            $markers,
        );
        $secondProcess = $this->mutationProcess(
            (int) $actor->getKey(),
            (int) $secondTarget->getKey(),
            $secondOperation,
            'second',
            $markers,
        );
        $firstRead = false;
        $secondAttempting = false;
        $secondReadBeforeRelease = false;

        try {
            $firstProcess->start();
            $firstRead = $this->waitForMarker($markers['first_read'], $firstProcess);

            if ($firstRead) {
                $secondProcess->start();
                $secondAttempting = $this->waitForMarker($markers['second_attempting'], $secondProcess);
                $secondReadBeforeRelease = $secondAttempting
                    && $this->markerAppearsWithin($markers['second_read'], $secondProcess, 0.75);
            }
        } finally {
            File::put($markers['release_first'], 'release');

            if ($firstProcess->isStarted()) {
                $firstProcess->wait();
            }

            if ($secondProcess->isStarted()) {
                $secondProcess->wait();
            }
        }

        try {
            $this->assertTrue($firstRead, 'First worker did not read administrators. '.$this->processFailure($firstProcess));
            $this->assertTrue($secondAttempting, 'Second worker did not attempt its mutation. '.$this->processFailure($secondProcess));
            $this->assertSame(0, $firstProcess->getExitCode(), $this->processFailure($firstProcess));
            $this->assertSame(0, $secondProcess->getExitCode(), $this->processFailure($secondProcess));

            return [
                $this->processResult($firstProcess),
                $this->processResult($secondProcess),
                $secondReadBeforeRelease,
            ];
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /** @param array<string, string> $markers */
    private function mutationProcess(
        int $actorId,
        int $targetId,
        string $operation,
        string $worker,
        array $markers,
    ): Process {
        $centralDatabase = config('database.connections.central.database');
        $tenantRoot = config('database.tenant_sqlite_root');
        $this->assertIsString($centralDatabase);
        $this->assertIsString($tenantRoot);

        $process = new Process(
            [PHP_BINARY, '-r', $this->mutationWorkerScript()],
            base_path(),
            [
                'APP_CONFIG_CACHE' => false,
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'CACHE_STORE' => 'array',
                'CENTRAL_DB_CONNECTION' => 'sqlite',
                'CENTRAL_DB_DATABASE' => $centralDatabase,
                'DB_CONNECTION' => 'central',
                'DB_DATABASE' => $centralDatabase,
                'DB_URL' => false,
                'TENANT_ATTESTATION_LOCK_PATH' => dirname($markers['first_read'])
                    .DIRECTORY_SEPARATOR.'attestation-locks',
                'TENANT_ADMIN_ACTOR_ID' => (string) $actorId,
                'TENANT_ADMIN_FIRST_READ' => $markers['first_read'],
                'TENANT_ADMIN_OPERATION' => $operation,
                'TENANT_ADMIN_RELEASE_FIRST' => $markers['release_first'],
                'TENANT_ADMIN_SECOND_ATTEMPTING' => $markers['second_attempting'],
                'TENANT_ADMIN_SECOND_READ' => $markers['second_read'],
                'TENANT_ADMIN_SHOP_ID' => resolve(TenantContext::class)->id(),
                'TENANT_ADMIN_TARGET_ID' => (string) $targetId,
                'TENANT_ADMIN_TENANT_ROOT' => $tenantRoot,
                'TENANT_ADMIN_WORKER' => $worker,
                'TENANT_DB_CONNECTION' => 'sqlite',
            ],
        );
        $process->setTimeout(20);

        return $process;
    }

    private function mutationWorkerScript(): string
    {
        return <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

            config()->set('database.tenant_sqlite_root', getenv('TENANT_ADMIN_TENANT_ROOT'));
            $template = config('database.tenant_connection_template');
            $template['busy_timeout'] = 5000;
            $template['journal_mode'] = 'WAL';
            $template['synchronous'] = 'NORMAL';
            config()->set('database.tenant_connection_template', $template);
            Illuminate\Support\Facades\DB::purge('central');

            $worker = getenv('TENANT_ADMIN_WORKER');

            if ($worker === 'second') {
                file_put_contents(getenv('TENANT_ADMIN_SECOND_ATTEMPTING'), 'attempting', LOCK_EX);
            }

            $shop = App\Models\Central\Shop::query()->findOrFail(getenv('TENANT_ADMIN_SHOP_ID'));
            $manager = $app->make(App\Tenancy\TenantConnectionManager::class);
            $manager->connect($shop);
            $actor = App\Models\User::query()->findOrFail((int) getenv('TENANT_ADMIN_ACTOR_ID'));
            $target = App\Models\User::query()->findOrFail((int) getenv('TENANT_ADMIN_TARGET_ID'));

            Illuminate\Support\Facades\Event::listen(
                Illuminate\Database\Events\QueryExecuted::class,
                static function (Illuminate\Database\Events\QueryExecuted $event) use ($worker): void {
                    $sql = strtolower($event->sql);

                    if ($event->connectionName !== 'tenant'
                        || ! str_starts_with(ltrim($sql), 'select')
                        || ! str_contains($sql, 'from "users"')) {
                        return;
                    }

                    if ($worker === 'second') {
                        file_put_contents(getenv('TENANT_ADMIN_SECOND_READ'), 'read', LOCK_EX);

                        return;
                    }

                    file_put_contents(getenv('TENANT_ADMIN_FIRST_READ'), 'read', LOCK_EX);
                    $deadline = microtime(true) + 10;

                    while (! is_file(getenv('TENANT_ADMIN_RELEASE_FIRST'))) {
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Timed out waiting to release the first mutation.');
                        }

                        usleep(10_000);
                    }
                },
            );

            try {
                $action = $app->make(App\Actions\ManageTenantUsers::class);

                match (getenv('TENANT_ADMIN_OPERATION')) {
                    'delete' => $action->delete($actor, $target),
                    'deactivate' => $action->update($actor, $target, ['is_active' => false], App\Enums\Role::Admin->value),
                    'demote' => $action->update($actor, $target, [], App\Enums\Role::Manager->value),
                    default => throw new RuntimeException('Unknown tenant administrator mutation.'),
                };

                $result = ['status' => 'ok'];
            } catch (Throwable $throwable) {
                $result = [
                    'status' => 'rejected',
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ];
            } finally {
                $manager->disconnect();
            }

            echo json_encode($result, JSON_THROW_ON_ERROR);
            PHP;
    }

    private function waitForMarker(string $marker, Process $process): bool
    {
        return $this->markerAppearsWithin($marker, $process, 5.0);
    }

    private function markerAppearsWithin(string $marker, Process $process, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while ($process->isRunning() && microtime(true) < $deadline) {
            if (is_file($marker)) {
                return true;
            }

            usleep(10_000);
        }

        return is_file($marker);
    }

    /** @return array{status: string, exception?: string, message?: string} */
    private function processResult(Process $process): array
    {
        return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function processFailure(Process $process): string
    {
        return trim($process->getErrorOutput().' '.$process->getOutput());
    }

    private function activeAdministratorCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', Role::Admin->value))
            ->count();
    }
}
