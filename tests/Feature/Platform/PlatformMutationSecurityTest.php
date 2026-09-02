<?php

namespace Tests\Feature\Platform;

use App\Actions\ManagePlatformUsers;
use App\Filament\Platform\Resources\PlatformUsers\Pages\CreatePlatformUser;
use App\Filament\Platform\Resources\PlatformUsers\Pages\EditPlatformUser;
use App\Filament\Platform\Resources\PlatformUsers\Pages\ListPlatformUsers;
use App\Models\Central\PlatformUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use RuntimeException;

class PlatformMutationSecurityTest extends PlatformTestCase
{
    public function test_management_action_reauthorizes_direct_create_edit_and_delete_calls(): void
    {
        $actor = PlatformUser::factory()->create();
        $target = PlatformUser::factory()->create(['name' => 'Original Name']);
        PlatformUser::factory()->create();
        $manager = resolve(ManagePlatformUsers::class);

        DB::connection('central')
            ->table('platform_users')
            ->where('id', $actor->getKey())
            ->update(['role' => 'support']);

        $this->assertThrows(
            fn () => $manager->create($actor, [
                'name' => 'Blocked Direct Create',
                'email' => 'blocked-direct-create@example.com',
                'password' => 'secret-password',
                'is_active' => false,
            ]),
            AuthorizationException::class,
        );
        $this->assertThrows(
            fn () => $manager->update($actor, $target, ['name' => 'Blocked Direct Rename']),
            AuthorizationException::class,
        );
        $this->assertThrows(
            fn () => $manager->delete($actor, $target),
            AuthorizationException::class,
        );

        $this->assertDatabaseMissing('platform_users', [
            'email' => 'blocked-direct-create@example.com',
        ], 'central');
        $this->assertSame('Original Name', $target->fresh()->name);
        $this->assertModelExists($target);
    }

    public function test_stale_deactivated_actor_cannot_create_a_platform_user(): void
    {
        $actor = PlatformUser::factory()->create();
        PlatformUser::factory()->create();

        $page = Livewire::actingAs($actor, 'platform')
            ->test(CreatePlatformUser::class)
            ->fillForm([
                'name' => 'Blocked Create',
                'email' => 'blocked-create@example.com',
                'password' => 'secret-password',
                'is_active' => true,
            ]);

        DB::connection('central')
            ->table('platform_users')
            ->where('id', $actor->getKey())
            ->update(['is_active' => false]);

        $this->callIgnoringAuthorization(static fn () => $page->call('create'));

        $this->assertDatabaseMissing('platform_users', [
            'email' => 'blocked-create@example.com',
        ], 'central');
    }

    public function test_stale_actor_without_super_admin_role_cannot_edit_a_platform_user(): void
    {
        $actor = PlatformUser::factory()->create();
        $target = PlatformUser::factory()->create(['name' => 'Original Name']);

        $page = Livewire::actingAs($actor, 'platform')
            ->test(EditPlatformUser::class, ['record' => $target->getKey()])
            ->fillForm(['name' => 'Blocked Rename']);

        DB::connection('central')
            ->table('platform_users')
            ->where('id', $actor->getKey())
            ->update(['role' => 'support']);

        $this->callIgnoringAuthorization(static fn () => $page->call('save'));

        $this->assertSame('Original Name', $target->fresh()->name);
    }

    public function test_stale_deactivated_actor_cannot_delete_a_platform_user(): void
    {
        $actor = PlatformUser::factory()->create();
        $target = PlatformUser::factory()->create();
        PlatformUser::factory()->create();

        $page = Livewire::actingAs($actor, 'platform')
            ->test(ListPlatformUsers::class)
            ->mountTableAction('delete', $target);

        DB::connection('central')
            ->table('platform_users')
            ->where('id', $actor->getKey())
            ->update(['is_active' => false]);

        $page->callMountedTableAction();

        $this->assertModelExists($target);
    }

    public function test_requested_inactive_state_is_used_for_the_initial_insert(): void
    {
        $actor = PlatformUser::factory()->create();
        $activeStateAtCreation = null;

        Event::listen(
            'eloquent.created: '.PlatformUser::class,
            static function (PlatformUser $platformUser) use (&$activeStateAtCreation): void {
                if ($platformUser->email === 'initially-inactive@example.com') {
                    $activeStateAtCreation = $platformUser->is_active;
                }
            },
        );

        Livewire::actingAs($actor, 'platform')
            ->test(CreatePlatformUser::class)
            ->fillForm([
                'name' => 'Initially Inactive',
                'email' => 'initially-inactive@example.com',
                'password' => 'secret-password',
                'is_active' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse($activeStateAtCreation);
        $this->assertFalse(PlatformUser::query()
            ->where('email', 'initially-inactive@example.com')
            ->sole()
            ->is_active);
    }

    public function test_failed_inactive_create_leaves_no_platform_user_row(): void
    {
        $actor = PlatformUser::factory()->create();

        Event::listen(
            'eloquent.created: '.PlatformUser::class,
            static function (PlatformUser $platformUser): void {
                if ($platformUser->email === 'failed-inactive@example.com') {
                    throw new RuntimeException('Simulated post-insert failure.');
                }
            },
        );

        try {
            Livewire::actingAs($actor, 'platform')
                ->test(CreatePlatformUser::class)
                ->fillForm([
                    'name' => 'Failed Inactive',
                    'email' => 'failed-inactive@example.com',
                    'password' => 'secret-password',
                    'is_active' => false,
                ])
                ->call('create');

            $this->fail('The simulated create failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated post-insert failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('platform_users', [
            'email' => 'failed-inactive@example.com',
        ], 'central');
    }

    private function callIgnoringAuthorization(callable $action): void
    {
        try {
            $action();
        } catch (AuthorizationException) {
            return;
        }
    }
}
