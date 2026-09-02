<?php

namespace Tests\Feature\Platform;

use App\Models\Central\PlatformUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

class PlatformAdministratorConcurrencyTest extends PlatformTestCase
{
    public function test_delete_wins_and_overlapping_deactivation_loses_on_separate_connections(): void
    {
        [$connectionA, $connectionB] = $this->configureWorkerConnections();
        $firstUser = PlatformUser::factory()->create();
        $secondUser = PlatformUser::factory()->create();
        $staleFirstUser = PlatformUser::on($connectionA)->findOrFail($firstUser->getKey());
        $competingSecondUser = PlatformUser::on($connectionB)->findOrFail($secondUser->getKey());
        $competingDeleteCommitted = false;

        Event::listen(
            'eloquent.updating: '.PlatformUser::class,
            static function (PlatformUser $platformUser) use (
                $connectionA,
                $firstUser,
                $competingSecondUser,
                &$competingDeleteCommitted,
            ): void {
                if ($platformUser->getConnectionName() !== $connectionA
                    || $platformUser->getKey() !== $firstUser->getKey()
                    || $competingDeleteCommitted) {
                    return;
                }

                $competingDeleteCommitted = $competingSecondUser->delete() === true;
            },
        );

        $deactivationFailure = null;

        try {
            $staleFirstUser->deactivate();
        } catch (Throwable $throwable) {
            $deactivationFailure = $throwable;
        } finally {
            Event::forget('eloquent.updating: '.PlatformUser::class);
        }

        $this->assertTrue($competingDeleteCommitted);
        $this->assertInstanceOf(QueryException::class, $deactivationFailure);
        $this->assertTrue($firstUser->fresh()->is_active);
        $this->assertModelMissing($secondUser);
        $this->assertSame(1, $this->activeSuperAdminCount());
    }

    public function test_deactivation_wins_and_overlapping_delete_loses_on_separate_connections(): void
    {
        [$connectionA, $connectionB] = $this->configureWorkerConnections();
        $firstUser = PlatformUser::factory()->create();
        $secondUser = PlatformUser::factory()->create();
        $staleFirstUser = PlatformUser::on($connectionA)->findOrFail($firstUser->getKey());
        $competingSecondUser = PlatformUser::on($connectionB)->findOrFail($secondUser->getKey());
        $competingDeactivationCommitted = false;

        Event::listen(
            'eloquent.deleting: '.PlatformUser::class,
            static function (PlatformUser $platformUser) use (
                $connectionA,
                $firstUser,
                $competingSecondUser,
                &$competingDeactivationCommitted,
            ): void {
                if ($platformUser->getConnectionName() !== $connectionA
                    || $platformUser->getKey() !== $firstUser->getKey()
                    || $competingDeactivationCommitted) {
                    return;
                }

                $competingSecondUser->deactivate();
                $competingDeactivationCommitted = true;
            },
        );

        $deleteFailure = null;

        try {
            $staleFirstUser->delete();
        } catch (Throwable $throwable) {
            $deleteFailure = $throwable;
        } finally {
            Event::forget('eloquent.deleting: '.PlatformUser::class);
        }

        $this->assertTrue($competingDeactivationCommitted);
        $this->assertInstanceOf(QueryException::class, $deleteFailure);
        $this->assertTrue($firstUser->fresh()->is_active);
        $this->assertFalse($secondUser->fresh()->is_active);
        $this->assertSame(1, $this->activeSuperAdminCount());
    }

    #[DataProvider('indirectDeleteMethods')]
    public function test_indirect_model_delete_paths_cannot_remove_the_final_active_super_admin(
        string $deleteMethod,
    ): void {
        $platformUser = PlatformUser::factory()->create();
        $caughtException = null;

        try {
            match ($deleteMethod) {
                'destroy' => PlatformUser::destroy($platformUser->getKey()),
                'deleteQuietly' => $platformUser->deleteQuietly(),
                'forceDelete' => $platformUser->forceDelete(),
            };
        } catch (LogicException $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(LogicException::class, $caughtException);
        $this->assertModelExists($platformUser);
        $this->assertSame(1, $this->activeSuperAdminCount());
    }

    /** @return array<string, array{string}> */
    public static function indirectDeleteMethods(): array
    {
        return [
            'destroy' => ['destroy'],
            'delete quietly' => ['deleteQuietly'],
            'force delete' => ['forceDelete'],
        ];
    }

    protected function tearDown(): void
    {
        DB::purge('central_worker_a');
        DB::purge('central_worker_b');

        parent::tearDown();
    }

    /** @return array{string, string} */
    private function configureWorkerConnections(): array
    {
        $connections = ['central_worker_a', 'central_worker_b'];

        foreach ($connections as $connection) {
            config()->set("database.connections.{$connection}", [
                'driver' => 'sqlite',
                'database' => $this->centralDatabasePath(),
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => 1000,
                'journal_mode' => 'WAL',
                'synchronous' => 'NORMAL',
                'transaction_mode' => 'DEFERRED',
            ]);
            DB::purge($connection);
        }

        return $connections;
    }

    private function activeSuperAdminCount(): int
    {
        return PlatformUser::query()
            ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->count();
    }
}
