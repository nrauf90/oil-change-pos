<?php

namespace Tests\Feature\Platform;

use App\Filament\Platform\Resources\PlatformUsers\Pages\CreatePlatformUser;
use App\Filament\Platform\Resources\PlatformUsers\Pages\EditPlatformUser;
use App\Filament\Platform\Resources\PlatformUsers\Pages\ListPlatformUsers;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopAccessSession;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use LogicException;

class PlatformAuthenticationTest extends PlatformTestCase
{
    public function test_platform_login_uses_email_copy_and_platform_branding(): void
    {
        $this->get('/platform/login')
            ->assertSuccessful()
            ->assertSee('Email address')
            ->assertSee('Platform administration')
            ->assertSee('Sign in')
            ->assertDontSee('Username');
    }

    public function test_active_super_admin_can_access_platform_without_tenant_context(): void
    {
        $platformUser = PlatformUser::factory()->create();

        $this->actingAs($platformUser, 'platform')
            ->get('/platform')
            ->assertSuccessful()
            ->assertSee('Platform overview');

        $this->assertFalse(resolve(TenantContext::class)->initialized());
    }

    public function test_tenant_identity_cannot_satisfy_the_platform_guard(): void
    {
        $tenantUser = User::factory()->make();
        $tenantUser->setAttribute($tenantUser->getKeyName(), 1);

        $this->actingAs($tenantUser, 'web')
            ->get('/platform')
            ->assertRedirect('/platform/login');

        $this->assertAuthenticatedAs($tenantUser, 'web');
        $this->assertGuest('platform');
    }

    public function test_platform_identity_cannot_satisfy_the_tenant_guard(): void
    {
        config()->set('app.url', 'https://pos.example.test');
        $shop = Shop::factory()->create(['slug' => 'tenant-guard-shop']);
        $this->createMigratedTenantDatabase($shop);
        $shop->markActive();
        $platformUser = PlatformUser::factory()->create();

        $this->actingAs($platformUser, 'platform')
            ->get('https://tenant-guard-shop.pos.example.test/admin')
            ->assertRedirectContains('/login');

        $this->assertAuthenticatedAs($platformUser, 'platform');
        $this->assertGuest('web');
    }

    public function test_inactive_platform_user_is_rejected_by_provider_and_panel(): void
    {
        PlatformUser::factory()->create();
        $inactiveUser = PlatformUser::factory()->create();
        $inactiveUser->deactivate();

        $this->assertFalse(Auth::guard('platform')->validate([
            'email' => $inactiveUser->email,
            'password' => 'password',
        ]));

        $this->actingAs($inactiveUser, 'platform')
            ->get('/platform')
            ->assertForbidden();
    }

    public function test_non_super_admin_platform_user_is_rejected_by_provider_and_panel(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $platformUser->forceFill(['role' => 'support'])->save();

        $this->assertFalse(Auth::guard('platform')->validate([
            'email' => $platformUser->email,
            'password' => 'password',
        ]));

        $this->actingAs($platformUser, 'platform')
            ->get('/platform')
            ->assertForbidden();
    }

    public function test_successful_platform_authentication_records_the_login_time(): void
    {
        $this->travelTo('2026-09-02 15:30:00');
        $platformUser = PlatformUser::factory()->create(['last_login_at' => null]);

        $this->assertTrue(Auth::guard('platform')->attempt([
            'email' => $platformUser->email,
            'password' => 'password',
        ]));

        $this->assertSame(
            '2026-09-02 15:30:00',
            $platformUser->fresh()->last_login_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_stale_delete_cannot_remove_the_final_active_super_admin(): void
    {
        $firstPlatformUser = PlatformUser::factory()->create();
        $secondPlatformUser = PlatformUser::factory()->create();
        $staleSecondPlatformUser = $secondPlatformUser->fresh();

        $firstPlatformUser->delete();
        $caughtException = null;

        try {
            $staleSecondPlatformUser->delete();
        } catch (LogicException $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(LogicException::class, $caughtException);
        $this->assertSame(
            'The final active super administrator cannot be deleted.',
            $caughtException->getMessage(),
        );
        $this->assertModelExists($secondPlatformUser);
    }

    public function test_force_delete_cannot_remove_the_final_active_super_admin(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $caughtException = null;

        try {
            $platformUser->forceDelete();
        } catch (LogicException $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(LogicException::class, $caughtException);
        $this->assertModelExists($platformUser);
        $this->assertSame(1, PlatformUser::query()
            ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->count());
    }

    public function test_platform_panel_configuration_uses_only_platform_components(): void
    {
        $panel = Filament::getPanels()['platform'] ?? null;

        $this->assertInstanceOf(Panel::class, $panel);

        $this->assertSame('platform', $panel->getId());
        $this->assertSame('platform', $panel->getPath());
        $this->assertSame('platform', $panel->getAuthGuard());
        $this->assertSame('resources/css/filament/platform/theme.css', $panel->getViteTheme());

        foreach ([...$panel->getPages(), ...$panel->getResources(), ...$panel->getWidgets()] as $component) {
            $component = is_string($component) ? $component : $component->widget;

            $this->assertStringStartsWith('App\\Filament\\Platform\\', $component);
        }
    }

    public function test_active_super_admin_lists_only_super_admin_accounts(): void
    {
        $this->assertTrue(class_exists(ListPlatformUsers::class));

        $currentUser = PlatformUser::factory()->create(['name' => 'Current Admin']);
        $visibleUser = PlatformUser::factory()->create(['name' => 'Visible Admin']);
        $otherPlatformUser = PlatformUser::factory()->create(['name' => 'Support Operator']);
        $otherPlatformUser->forceFill(['role' => 'support'])->save();

        Livewire::actingAs($currentUser, 'platform')
            ->test(ListPlatformUsers::class)
            ->assertCanSeeTableRecords([$currentUser, $visibleUser])
            ->assertCanNotSeeTableRecords([$otherPlatformUser])
            ->assertSee('Current Admin')
            ->assertSee('You')
            ->assertSee('Visible Admin')
            ->assertDontSee('Support Operator');
    }

    public function test_active_super_admin_creates_another_active_super_admin(): void
    {
        $this->assertTrue(class_exists(CreatePlatformUser::class));

        $currentUser = PlatformUser::factory()->create();

        Livewire::actingAs($currentUser, 'platform')
            ->test(CreatePlatformUser::class)
            ->fillForm([
                'name' => 'Ayesha Malik',
                'email' => 'ayesha@example.com',
                'password' => 'secret-password',
                'is_active' => true,
            ])
            ->set('data.role', 'support')
            ->call('create')
            ->assertHasNoFormErrors();

        $createdUser = PlatformUser::query()->where('email', 'ayesha@example.com')->sole();

        $this->assertSame('Ayesha Malik', $createdUser->name);
        $this->assertSame(PlatformUser::ROLE_SUPER_ADMIN, $createdUser->role);
        $this->assertTrue($createdUser->is_active);
        $this->assertTrue(Hash::check('secret-password', $createdUser->password));
    }

    public function test_platform_user_creation_can_create_an_inactive_account(): void
    {
        $this->assertTrue(class_exists(CreatePlatformUser::class));

        $currentUser = PlatformUser::factory()->create();

        Livewire::actingAs($currentUser, 'platform')
            ->test(CreatePlatformUser::class)
            ->fillForm([
                'name' => 'Inactive Admin',
                'email' => 'inactive-new@example.com',
                'password' => 'secret-password',
                'is_active' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(PlatformUser::query()
            ->where('email', 'inactive-new@example.com')
            ->sole()
            ->is_active);
    }

    public function test_platform_user_creation_rejects_duplicate_email(): void
    {
        $this->assertTrue(class_exists(CreatePlatformUser::class));

        $currentUser = PlatformUser::factory()->create();
        PlatformUser::factory()->create(['email' => 'taken@example.com']);

        Livewire::actingAs($currentUser, 'platform')
            ->test(CreatePlatformUser::class)
            ->fillForm([
                'name' => 'Duplicate Admin',
                'email' => 'taken@example.com',
                'password' => 'secret-password',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);

        $this->assertSame(1, PlatformUser::query()->where('email', 'taken@example.com')->count());
    }

    public function test_platform_user_creation_rejects_short_password(): void
    {
        $this->assertTrue(class_exists(CreatePlatformUser::class));

        $currentUser = PlatformUser::factory()->create();

        Livewire::actingAs($currentUser, 'platform')
            ->test(CreatePlatformUser::class)
            ->fillForm([
                'name' => 'Short Password',
                'email' => 'short@example.com',
                'password' => 'short',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertSame(0, PlatformUser::query()->where('email', 'short@example.com')->count());
    }

    public function test_blank_password_on_edit_preserves_the_existing_password(): void
    {
        $this->assertTrue(class_exists(EditPlatformUser::class));

        $currentUser = PlatformUser::factory()->create();
        $editedUser = PlatformUser::factory()->create(['password' => 'original-password']);

        Livewire::actingAs($currentUser, 'platform')
            ->test(EditPlatformUser::class, ['record' => $editedUser->getKey()])
            ->fillForm([
                'name' => 'Renamed Admin',
                'password' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $editedUser->refresh();

        $this->assertSame('Renamed Admin', $editedUser->name);
        $this->assertTrue(Hash::check('original-password', $editedUser->password));
    }

    public function test_platform_user_edit_deactivates_through_the_controlled_transition(): void
    {
        $this->assertTrue(class_exists(EditPlatformUser::class));

        $currentUser = PlatformUser::factory()->create();
        $editedUser = PlatformUser::factory()->create();

        Livewire::actingAs($currentUser, 'platform')
            ->test(EditPlatformUser::class, ['record' => $editedUser->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($editedUser->fresh()->is_active);
    }

    public function test_stale_self_edit_cannot_deactivate_the_final_active_super_admin(): void
    {
        $this->assertTrue(class_exists(EditPlatformUser::class));

        $currentUser = PlatformUser::factory()->create();
        $otherUser = PlatformUser::factory()->create();
        $editPage = Livewire::actingAs($currentUser, 'platform')
            ->test(EditPlatformUser::class, ['record' => $currentUser->getKey()]);

        $otherUser->deactivate();

        $editPage
            ->set('data.is_active', false)
            ->call('save');

        $this->assertTrue($currentUser->fresh()->is_active);
        $this->assertSame(1, PlatformUser::query()
            ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->count());
    }

    public function test_final_active_super_admin_edit_explains_why_deactivation_is_locked(): void
    {
        $currentUser = PlatformUser::factory()->create();

        Livewire::actingAs($currentUser, 'platform')
            ->test(EditPlatformUser::class, ['record' => $currentUser->getKey()])
            ->assertFormFieldDisabled('is_active')
            ->assertSee('Activate another super admin before deactivating this account.');
    }

    public function test_delete_action_is_disabled_for_the_final_active_super_admin(): void
    {
        $this->assertTrue(class_exists(ListPlatformUsers::class));

        $currentUser = PlatformUser::factory()->create();

        Livewire::actingAs($currentUser, 'platform')
            ->test(ListPlatformUsers::class)
            ->assertTableActionDisabled('delete', $currentUser)
            ->assertSee('Add or activate another super admin before deleting this account.');
    }

    public function test_active_super_admin_deletes_another_platform_user(): void
    {
        $this->assertTrue(class_exists(ListPlatformUsers::class));

        $currentUser = PlatformUser::factory()->create();
        $deletedUser = PlatformUser::factory()->create();

        Livewire::actingAs($currentUser, 'platform')
            ->test(ListPlatformUsers::class)
            ->callTableAction('delete', $deletedUser);

        $this->assertModelMissing($deletedUser);
    }

    public function test_platform_user_delete_reports_a_failure_when_audit_history_requires_the_account(): void
    {
        $currentUser = PlatformUser::factory()->create();
        $auditedUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();
        ShopAccessSession::start($auditedUser, $shop, 'Investigating a support request');

        Livewire::actingAs($currentUser, 'platform')
            ->test(ListPlatformUsers::class)
            ->callTableAction('delete', $auditedUser)
            ->assertNotified('Platform user could not be deleted');

        $this->assertModelExists($auditedUser);
    }
}
