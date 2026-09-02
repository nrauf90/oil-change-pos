<?php

namespace Tests\Feature\Platform;

use App\Enums\ShopStatus;
use App\Filament\Platform\Resources\ShopAccessSessions\Pages\ListShopAccessSessions;
use App\Filament\Platform\Resources\ShopAccessSessions\ShopAccessSessionResource;
use App\Filament\Platform\Resources\Shops\Pages\ViewShop;
use App\Http\Middleware\EnforceReadOnlySupportAccess;
use App\Http\Middleware\InitializeSupportAccess;
use App\Http\Middleware\InitializeTenancy;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopAccessSession;
use App\Models\Item;
use App\Models\User;
use App\Tenancy\SupportAccessContext;
use App\Tenancy\SupportAccessManager;
use App\Tenancy\SupportAccessPrincipal;
use App\Tenancy\TenantConnectionManager;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class SupportAccessTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('u', 32)));
        config()->set('app.url', 'https://pos.example.test');

        Route::middleware('web')
            ->post('/__support-access/start/{shop}', function (
                Shop $shop,
                SupportAccessManager $manager,
            ): JsonResponse {
                $platformUser = PlatformUser::query()->findOrFail(
                    request()->string('platform_user_id')->toString(),
                );
                $audit = $manager->start($platformUser, $shop, request()->string('reason')->toString());

                return response()->json(['audit_id' => $audit->getKey()]);
            });

        Route::middleware(['web', InitializeSupportAccess::class])
            ->get('/__support-access/context', function (SupportAccessContext $context): JsonResponse {
                return response()->json([
                    'active' => $context->active(),
                    'audit_id' => $context->audit()->getKey(),
                    'shop_id' => $context->shop()->getKey(),
                    'platform_user_id' => $context->platformUser()->getKey(),
                    'principal_class' => $context->principal()::class,
                ]);
            });

        Route::middleware(['web', InitializeSupportAccess::class])
            ->get('/__support-access/status', fn (SupportAccessContext $context): JsonResponse => response()->json([
                'active' => $context->active(),
            ]));

        Route::middleware([
            'web',
            InitializeSupportAccess::class,
            EnforceReadOnlySupportAccess::class,
        ])->match(
            ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'],
            '/__support-access/gated',
            function (): JsonResponse {
                SupportMutationProbe::$calls++;
                $principal = auth('web')->user();

                if (! $principal instanceof SupportAccessPrincipal) {
                    abort(500, 'The support principal was not installed.');
                }

                return response()->json([
                    'principal_is_authenticatable' => $principal instanceof Authenticatable,
                    'principal_is_authorizable' => $principal instanceof Authorizable,
                    'principal_is_filament_user' => $principal instanceof FilamentUser,
                    'identifier' => $principal->getAuthIdentifier(),
                    'name' => $principal->name,
                    'role' => $principal->role()->label(),
                    'is_admin' => $principal->isAdmin(),
                    'can_read_items' => $principal->can('items.view_any'),
                    'can_create_items' => $principal->can('items.create'),
                    'can_any_read' => $principal->canAny(['items.create', 'items.view_any']),
                    'has_any_read_permission' => $principal->hasAnyPermission(
                        'items.create',
                        'items.view_any',
                    ),
                    'can_access_admin_panel' => $principal->canAccessPanel(
                        Filament::getPanels()['admin'],
                    ),
                    'web_session_key_present' => request()->session()->has(auth('web')->getName()),
                ]);
            },
        );

        Route::middleware([
            'web',
            InitializeSupportAccess::class,
            'shop.active',
            'tenant',
            EnforceReadOnlySupportAccess::class,
        ])->match(
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            '/__support-access/tenant-mutation/{item}',
            static function (Item $item): JsonResponse {
                $item->update(['name' => 'MUTATED BY SUPPORT']);

                return response()->json(['mutated' => true]);
            },
        );
    }

    public function test_active_super_admin_starts_access_with_exact_audit_metadata_and_session_rotation(): void
    {
        $this->travelTo('2026-09-02 14:15:16');
        $platformUser = PlatformUser::factory()->create([
            'name' => 'Support Operator',
            'is_active' => true,
        ]);
        $shop = Shop::factory()->create([
            'name' => 'North Workshop',
            'status' => ShopStatus::Active,
        ]);
        $this->actingAs($platformUser, 'platform')->withSession(['session-sentinel' => 'preserved']);
        $oldSessionId = session()->getId();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->withHeader('User-Agent', 'Support Browser/10.0')
            ->postJson('/__support-access/start/'.$shop->getKey(), [
                'platform_user_id' => $platformUser->getKey(),
                'reason' => 'Investigating invoice totals',
            ]);

        $response->assertSuccessful();

        $audit = ShopAccessSession::query()->sole();

        $response
            ->assertJsonPath('audit_id', $audit->getKey())
            ->assertSessionHas('platform.support_access', [
                'audit_id' => $audit->getKey(),
                'shop_id' => $shop->getKey(),
            ])
            ->assertSessionHas('session-sentinel', 'preserved');

        $this->assertSame($platformUser->getKey(), $audit->platform_user_id);
        $this->assertSame($shop->getKey(), $audit->shop_id);
        $this->assertSame('Investigating invoice totals', $audit->reason);
        $this->assertSame('203.0.113.42', $audit->ip_address);
        $this->assertSame('Support Browser/10.0', $audit->user_agent);
        $this->assertSame('2026-09-02 14:15:16', $audit->started_at?->format('Y-m-d H:i:s'));
        $this->assertNull($audit->ended_at);
        $this->assertNotSame($oldSessionId, session()->getId());

        $this->assertAuthenticatedAs($platformUser, 'platform');
        $this->assertGuest('web');
    }

    #[DataProvider('unauthorizedOperatorCases')]
    public function test_start_rejects_operator_without_a_live_active_super_admin_guard(string $case): void
    {
        $operator = PlatformUser::factory()->create();
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);

        if ($case === 'inactive') {
            $operator->forceFill(['is_active' => false])->save();
            $this->actingAs($operator, 'platform');
        } elseif ($case === 'non-super-admin') {
            $operator->forceFill(['role' => 'support'])->save();
            $this->actingAs($operator, 'platform');
        } elseif ($case === 'different-operator') {
            $this->actingAs(PlatformUser::factory()->create(), 'platform');
        }

        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $operator->getKey(),
            'reason' => 'Must be rejected',
        ])->assertForbidden();

        $this->assertDatabaseCount('shop_access_sessions', 0, 'central');
        $this->assertFalse(session()->has('platform.support_access'));
    }

    #[DataProvider('unavailableShopStatuses')]
    public function test_start_rejects_every_shop_that_is_not_active(string $status): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create(['status' => ShopStatus::from($status)]);
        $this->actingAs($platformUser, 'platform');

        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Must be rejected',
        ])->assertForbidden();

        $this->assertDatabaseCount('shop_access_sessions', 0, 'central');
        $this->assertFalse(session()->has('platform.support_access'));
    }

    public function test_start_removes_tenant_identity_and_selector_but_preserves_platform_authentication(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);
        Auth::guard('platform')->login($platformUser);
        $platformLoginKey = Auth::guard('platform')->getName();
        $webLoginKey = Auth::guard('web')->getName();
        $tenantRecaller = Auth::guard('web')->getRecallerName();
        $this->withSession([
            $webLoginKey => 4321,
            InitializeTenancy::SESSION_SHOP_KEY => 'stale-shop-id',
        ])->withCookie($tenantRecaller, 'stale-tenant-recaller');

        $response = $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Read-only investigation',
        ]);

        $response
            ->assertSuccessful()
            ->assertSessionHas($platformLoginKey, $platformUser->getKey())
            ->assertSessionMissing($webLoginKey)
            ->assertSessionMissing(InitializeTenancy::SESSION_SHOP_KEY)
            ->assertCookieExpired($tenantRecaller);

        $this->assertAuthenticatedAs($platformUser, 'platform');
        $this->assertGuest('web');
    }

    public function test_starting_replacement_access_ends_the_previous_audit_once(): void
    {
        $this->travelTo('2026-09-02 14:00:00');
        $platformUser = PlatformUser::factory()->create();
        $firstShop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $secondShop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $this->actingAs($platformUser, 'platform');

        $this->postJson('/__support-access/start/'.$firstShop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'First shop',
        ])->assertSuccessful();

        $firstAudit = ShopAccessSession::query()->sole();
        $this->travelTo('2026-09-02 14:30:00');

        $this->postJson('/__support-access/start/'.$secondShop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Second shop',
        ])->assertSuccessful();

        $secondAudit = ShopAccessSession::query()->whereKeyNot($firstAudit->getKey())->sole();

        $this->assertSame('2026-09-02 14:30:00', $firstAudit->fresh()->ended_at?->format('Y-m-d H:i:s'));
        $this->assertNull($secondAudit->ended_at);
        $this->assertSame(1, ShopAccessSession::query()->whereNull('ended_at')->count());
        $this->assertSame(
            [
                'audit_id' => $secondAudit->getKey(),
                'shop_id' => $secondShop->getKey(),
            ],
            session('platform.support_access'),
        );
    }

    public function test_valid_server_side_tuple_resumes_exact_request_context_and_ignores_query_selectors(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $this->actingAs($platformUser, 'platform');

        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Resume context',
        ])->assertSuccessful();

        $audit = ShopAccessSession::query()->sole();

        $this->get('/__support-access/context?audit_id=forged-audit&tenant=forged-shop')
            ->assertSuccessful()
            ->assertExactJson([
                'active' => true,
                'audit_id' => $audit->getKey(),
                'shop_id' => $shop->getKey(),
                'platform_user_id' => $platformUser->getKey(),
                'principal_class' => SupportAccessPrincipal::class,
            ]);

        $this->assertNull($audit->fresh()->ended_at);
        $this->assertAuthenticatedAs($platformUser, 'platform');
        $this->assertGuest('web');
    }

    public function test_missing_support_tuple_preserves_an_ordinary_tenant_session(): void
    {
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $webGuardKey = Auth::guard('web')->getName();
        $this->withSession([
            $webGuardKey => 123,
            InitializeTenancy::SESSION_SHOP_KEY => $shop->getKey(),
            'tenant-session-sentinel' => 'preserved',
        ]);
        $this->get('/__support-access/status')
            ->assertSuccessful()
            ->assertExactJson(['active' => false])
            ->assertSessionHas($webGuardKey, 123)
            ->assertSessionHas(InitializeTenancy::SESSION_SHOP_KEY, $shop->getKey())
            ->assertSessionHas('tenant-session-sentinel', 'preserved')
            ->assertSessionMissing(SupportAccessManager::SESSION_KEY);
    }

    #[DataProvider('invalidTupleCases')]
    public function test_invalid_support_tuple_clears_and_rotates_local_state(string $case): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $otherShop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $this->actingAs($platformUser, 'platform');
        $audit = null;

        if ($case === 'malformed') {
            $state = ['audit_id' => 'not-a-complete-tuple'];
        } elseif ($case === 'unknown') {
            $state = [
                'audit_id' => '018f0000-0000-7000-8000-000000000000',
                'shop_id' => $shop->getKey(),
            ];
        } else {
            $audit = ShopAccessSession::start($platformUser, $shop);
            $state = [
                'audit_id' => $audit->getKey(),
                'shop_id' => $case === 'shop-mismatch' ? $otherShop->getKey() : $shop->getKey(),
            ];

            if ($case === 'ended') {
                $audit->end();
            }
        }

        $this->withSession(['platform.support_access' => $state]);
        $oldSessionId = session()->getId();

        $this->get('/__support-access/status')
            ->assertSuccessful()
            ->assertExactJson(['active' => false])
            ->assertSessionMissing('platform.support_access');

        $this->assertNotSame($oldSessionId, session()->getId());

        if ($audit instanceof ShopAccessSession && $case === 'shop-mismatch') {
            $this->assertNotNull($audit->fresh()->ended_at);
        }
    }

    public function test_cross_admin_tuple_cannot_use_or_end_another_operators_audit(): void
    {
        $currentPlatformUser = PlatformUser::factory()->create();
        $otherPlatformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $otherAudit = ShopAccessSession::start($otherPlatformUser, $shop);
        $this->actingAs($currentPlatformUser, 'platform')->withSession([
            'platform.support_access' => [
                'audit_id' => $otherAudit->getKey(),
                'shop_id' => $shop->getKey(),
            ],
        ]);
        $oldSessionId = session()->getId();

        $this->get('/__support-access/status')
            ->assertSuccessful()
            ->assertExactJson(['active' => false])
            ->assertSessionMissing('platform.support_access');

        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNull($otherAudit->fresh()->ended_at);
    }

    public function test_unsigned_query_selectors_cannot_create_support_context(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $audit = ShopAccessSession::start($platformUser, $shop);
        $this->actingAs($platformUser, 'platform');

        $this->get('/__support-access/status?audit_id='.$audit->getKey().'&shop_id='.$shop->getKey())
            ->assertSuccessful()
            ->assertExactJson(['active' => false])
            ->assertSessionMissing('platform.support_access');

        $this->assertNull($audit->fresh()->ended_at);
    }

    public function test_safe_request_installs_non_persisted_read_only_principal_for_the_request_only(): void
    {
        $platformUser = PlatformUser::factory()->create(['name' => 'Ayesha Support']);
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $this->actingAs($platformUser, 'platform');

        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Principal separation',
        ])->assertSuccessful();

        $audit = ShopAccessSession::query()->sole();
        SupportMutationProbe::$calls = 0;

        $this->get('/__support-access/gated')
            ->assertSuccessful()
            ->assertExactJson([
                'principal_is_authenticatable' => true,
                'principal_is_authorizable' => true,
                'principal_is_filament_user' => true,
                'identifier' => $audit->getKey(),
                'name' => 'Ayesha Support',
                'role' => 'Admin / Owner',
                'is_admin' => true,
                'can_read_items' => true,
                'can_create_items' => false,
                'can_any_read' => true,
                'has_any_read_permission' => true,
                'can_access_admin_panel' => true,
                'web_session_key_present' => false,
            ]);

        $this->assertSame(1, SupportMutationProbe::$calls);
        $this->assertGuest('web');
        $this->assertFalse(session()->has(Auth::guard('web')->getName()));
        $this->assertAuthenticatedAs($platformUser, 'platform');
    }

    public function test_real_tenant_blade_get_renders_data_and_banner_without_tenant_identity(): void
    {
        $platformUser = PlatformUser::factory()->create(['name' => 'Ayesha <Support>']);
        $shop = $this->createActiveTenant('north-workshop', 'North <script>alert(1)</script>');
        $item = resolve(TenantConnectionManager::class)->within(
            $shop,
            static fn (): Item => Item::factory()->create(['name' => 'Synthetic Support Oil']),
        );
        $this->actingAs($platformUser, 'platform');

        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Inspect inventory',
        ])->assertSuccessful();
        $audit = ShopAccessSession::query()->sole();

        $this->get('https://pos.example.test/items')
            ->assertSuccessful()
            ->assertSee($item->name)
            ->assertSee('Viewing shop as super admin')
            ->assertSee('Read-only support view')
            ->assertSee($shop->name)
            ->assertSee($platformUser->name)
            ->assertSee(route('support-access.exit'), false)
            ->assertSee('name="_token"', false)
            ->assertDontSee((string) $audit->getKey(), false)
            ->assertDontSee('<script>alert(1)</script>', false);

        $tenantUserCount = resolve(TenantConnectionManager::class)->within(
            $shop,
            static fn (): int => User::query()->count(),
        );

        $this->assertSame(0, $tenantUserCount);
        $this->assertFalse(session()->has(Auth::guard('web')->getName()));
        $this->assertAuthenticatedAs($platformUser, 'platform');
    }

    public function test_wrong_shop_host_rejects_request_without_ending_valid_audit(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->createActiveTenant('correct-shop');
        Shop::factory()->create(['slug' => 'wrong-shop', 'status' => ShopStatus::Active]);
        $this->actingAs($platformUser, 'platform');
        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Host boundary',
        ])->assertSuccessful();
        $audit = ShopAccessSession::query()->sole();

        $this->get('https://wrong-shop.pos.example.test/items')->assertNotFound();

        $this->assertNull($audit->fresh()->ended_at);
        $this->assertSame((string) $audit->getKey(), session('platform.support_access.audit_id'));
    }

    public function test_explicit_exit_ends_audit_rotates_state_and_uses_configured_central_redirect(): void
    {
        $this->travelTo('2026-09-03 10:00:00');
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->createActiveTenant('exit-shop');
        $this->actingAs($platformUser, 'platform');
        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Exit lifecycle',
        ])->assertSuccessful();
        $audit = ShopAccessSession::query()->sole();
        $oldSessionId = session()->getId();
        $this->travelTo('2026-09-03 10:15:00');

        $this->post('https://pos.example.test/support-access/exit', [
            '_token' => session()->token(),
            'return' => 'https://attacker.example/steal',
        ], ['HTTP_REFERER' => 'https://attacker.example/previous'])
            ->assertRedirect('https://pos.example.test/platform/shops/'.$shop->getKey())
            ->assertSessionMissing(SupportAccessManager::SESSION_KEY)
            ->assertSessionMissing(InitializeTenancy::SESSION_SHOP_KEY);

        $this->assertSame('2026-09-03 10:15:00', $audit->fresh()->ended_at?->format('Y-m-d H:i:s'));
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertAuthenticatedAs($platformUser, 'platform');
        $this->assertGuest('web');
    }

    public function test_exit_requires_csrf_and_leaves_active_audit_untouched_when_token_is_missing(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->createActiveTenant('csrf-shop');
        $this->actingAs($platformUser, 'platform');
        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'CSRF boundary',
        ])->assertSuccessful();
        $audit = ShopAccessSession::query()->sole();
        $this->app->bind(PreventRequestForgery::class, EnforcedRequestForgery::class);

        $this->postJson('https://pos.example.test/support-access/exit')->assertStatus(419);

        $this->assertNull($audit->fresh()->ended_at);
        $this->assertSame((string) $audit->getKey(), session('platform.support_access.audit_id'));
    }

    public function test_suspension_after_start_ends_access_before_tenant_authentication(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->createActiveTenant('suspended-during-support');
        $this->actingAs($platformUser, 'platform');
        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Suspension boundary',
        ])->assertSuccessful();
        $audit = ShopAccessSession::query()->sole();
        $oldSessionId = session()->getId();
        $shop->suspend();

        $this->get('https://pos.example.test/items')
            ->assertStatus(503)
            ->assertSee('Shop unavailable');

        $this->assertNotNull($audit->fresh()->ended_at);
        $this->assertFalse(session()->has(SupportAccessManager::SESSION_KEY));
        $this->assertNotSame($oldSessionId, session()->getId());
        $tenantUserCount = resolve(TenantConnectionManager::class)->within(
            $shop,
            static fn (): int => User::query()->count(),
        );
        $this->assertSame(0, $tenantUserCount);
        $this->assertGuest('web');
    }

    #[DataProvider('unsafeSupportMethods')]
    public function test_every_unsafe_tenant_method_is_blocked_before_model_binding_or_mutation(string $method): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->createActiveTenant('mutation-'.$method);
        $item = resolve(TenantConnectionManager::class)->within(
            $shop,
            static fn (): Item => Item::factory()->create(['name' => 'Immutable Support Item']),
        );
        $this->actingAs($platformUser, 'platform');
        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Mutation boundary',
        ])->assertSuccessful();

        $this->call(
            $method,
            'https://pos.example.test/__support-access/tenant-mutation/'.$item->getKey(),
        )->assertForbidden();

        $persistedName = resolve(TenantConnectionManager::class)->within(
            $shop,
            static fn (): string => (string) Item::query()->sole()->name,
        );
        $this->assertSame('Immutable Support Item', $persistedName);
        $this->assertNull(ShopAccessSession::query()->sole()->ended_at);
    }

    #[DataProvider('livewireSupportEndpoints')]
    public function test_real_livewire_and_upload_posts_are_forbidden_before_tenant_mutation(string $routeName): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->createActiveTenant('livewire-support');
        resolve(TenantConnectionManager::class)->within(
            $shop,
            static fn (): Item => Item::factory()->create(['name' => 'Livewire Boundary Item']),
        );
        $this->actingAs($platformUser, 'platform');
        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Livewire boundary',
        ])->assertSuccessful();
        $route = Route::getRoutes()->getByName($routeName);
        $this->assertNotNull($route);
        $url = 'https://pos.example.test/'.str_replace('{tenant}', $shop->slug, $route->uri());

        $this->withHeader('X-Livewire', 'true')->postJson($url, [
            'components' => [],
        ])->assertForbidden();

        $persistedName = resolve(TenantConnectionManager::class)->within(
            $shop,
            static fn (): string => (string) Item::query()->sole()->name,
        );
        $this->assertSame('Livewire Boundary Item', $persistedName);
    }

    public function test_initial_tenant_filament_get_renders_under_support_principal_with_banner(): void
    {
        $platformUser = PlatformUser::factory()->create(['name' => 'Filament Support']);
        $shop = $this->createActiveTenant('filament-support');
        resolve(TenantConnectionManager::class)->within(
            $shop,
            static fn (): Item => Item::factory()->create(['name' => 'Filament Support Oil']),
        );
        $this->actingAs($platformUser, 'platform');
        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Filament read',
        ])->assertSuccessful();
        $audit = ShopAccessSession::query()->sole();

        $this->get('https://pos.example.test/admin/items')
            ->assertSuccessful()
            ->assertSee('Filament Support Oil')
            ->assertSee('Viewing shop as super admin')
            ->assertSee('Read-only support view')
            ->assertDontSee((string) $audit->getKey(), false);

        $this->assertFalse(session()->has(Auth::guard('web')->getName()));
        $this->assertAuthenticatedAs($platformUser, 'platform');
    }

    public function test_platform_shop_action_starts_audited_access_and_redirects_without_bearer_claims(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $this->actingAs($platformUser, 'platform')->withSession([]);
        $detachSessionHook = Livewire::listen('request', static function (): void {
            request()->setLaravelSession(app('session.store'));
        });

        try {
            Livewire::actingAs($platformUser, 'platform')
                ->test(ViewShop::class, ['record' => $shop->getKey()])
                ->assertActionVisible('supportAccess')
                ->callAction('supportAccess', ['reason' => 'Review inventory discrepancy'])
                ->assertHasNoActionErrors()
                ->assertRedirect('https://pos.example.test/admin');
        } finally {
            $detachSessionHook();
        }

        $audit = ShopAccessSession::query()->sole();
        $this->assertSame('Review inventory discrepancy', $audit->reason);
        $this->assertSame((string) $audit->getKey(), session('platform.support_access.audit_id'));
        $this->assertStringNotContainsString((string) $audit->getKey(), 'https://pos.example.test/admin');
    }

    public function test_audit_resource_is_newest_first_eager_loaded_and_read_only(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create(['name' => 'Audit <Shop>']);
        $this->travelTo('2026-09-03 09:00:00');
        $older = ShopAccessSession::start(
            $platformUser,
            $shop,
            '<script>old reason</script>',
            '203.0.113.10',
            '<img src=x onerror=alert(1)>',
        );
        $this->travelTo('2026-09-03 10:00:00');
        $newer = ShopAccessSession::start($platformUser, $shop, 'Newer reason', '203.0.113.11');

        Livewire::actingAs($platformUser, 'platform')
            ->test(ListShopAccessSessions::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
            ->assertSee($shop->name)
            ->assertSee($older->reason)
            ->assertDontSee('<script>old reason</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);

        $records = ShopAccessSessionResource::getEloquentQuery()->get();
        $this->assertSame([$newer->getKey(), $older->getKey()], $records->modelKeys());
        $this->assertTrue($records->every(
            static fn (ShopAccessSession $record): bool => $record->relationLoaded('shop')
                && $record->relationLoaded('platformUser'),
        ));
        $this->assertFalse(ShopAccessSessionResource::canCreate());
        $this->assertFalse(ShopAccessSessionResource::canEdit($older));
        $this->assertFalse(ShopAccessSessionResource::canDelete($older));
        $this->assertFalse(ShopAccessSessionResource::canDeleteAny());
    }

    #[DataProvider('safeSupportMethods')]
    public function test_read_only_gate_allows_only_safe_http_methods(string $method): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $this->actingAs($platformUser, 'platform');
        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Safe method',
        ])->assertSuccessful();
        SupportMutationProbe::$calls = 0;

        $this->call($method, '/__support-access/gated')->assertSuccessful();

        $this->assertSame(1, SupportMutationProbe::$calls);
    }

    #[DataProvider('unsafeSupportMethods')]
    public function test_read_only_gate_forbids_every_unsafe_http_method_before_controller_code(string $method): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);
        $this->actingAs($platformUser, 'platform');
        $this->postJson('/__support-access/start/'.$shop->getKey(), [
            'platform_user_id' => $platformUser->getKey(),
            'reason' => 'Unsafe method',
        ])->assertSuccessful();
        $audit = ShopAccessSession::query()->sole();
        SupportMutationProbe::$calls = 0;

        $this->call($method, '/__support-access/gated')->assertForbidden();

        $this->assertSame(0, SupportMutationProbe::$calls);
        $this->assertNull($audit->fresh()->ended_at);
    }

    public static function unauthorizedOperatorCases(): array
    {
        return [
            'unauthenticated' => ['unauthenticated'],
            'inactive' => ['inactive'],
            'non-super-admin' => ['non-super-admin'],
            'different operator' => ['different-operator'],
        ];
    }

    public static function unavailableShopStatuses(): array
    {
        return [
            'provisioning' => [ShopStatus::Provisioning->value],
            'failed' => [ShopStatus::Failed->value],
            'suspended' => [ShopStatus::Suspended->value],
        ];
    }

    public static function invalidTupleCases(): array
    {
        return [
            'malformed shape' => ['malformed'],
            'unknown audit' => ['unknown'],
            'ended audit' => ['ended'],
            'shop mismatch' => ['shop-mismatch'],
        ];
    }

    public static function safeSupportMethods(): array
    {
        return [
            'GET' => ['GET'],
            'HEAD' => ['HEAD'],
            'OPTIONS' => ['OPTIONS'],
        ];
    }

    public static function unsafeSupportMethods(): array
    {
        return [
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'PATCH' => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    public static function livewireSupportEndpoints(): array
    {
        return [
            'canonical Livewire update' => ['livewire.update'],
            'local tenant Livewire update' => ['tenant.local.livewire.update'],
            'canonical upload' => ['livewire.upload-file'],
            'local tenant upload' => ['tenant.local.livewire.upload-file'],
        ];
    }

    private function createActiveTenant(string $slug, string $name = 'Support Shop'): Shop
    {
        $database = rtrim((string) config('database.tenant_sqlite_root'), '/\\')
            .DIRECTORY_SEPARATOR.$slug.'-'.Str::uuid().'.sqlite';
        $shop = Shop::registerForProvisioning(
            name: $name,
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $database,
        );

        $this->createMigratedTenantDatabase($shop);
        $shop->markActive();

        return $shop->fresh();
    }
}

final class SupportMutationProbe
{
    public static int $calls = 0;
}

final class EnforcedRequestForgery extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
