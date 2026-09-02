<?php

namespace Tests\Feature\Tenancy;

use App\Enums\ShopStatus;
use App\Http\Middleware\EnsureFilamentActionMatchesTenant;
use App\Http\Middleware\EnsureLivewireUploadMatchesTenant;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Http\Middleware\EnsureShopIsActive;
use App\Http\Middleware\InitializeTenancy;
use App\Http\Middleware\TenantThrottleRequests;
use App\Models\Central\Shop;
use App\Models\Item;
use App\Models\User;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLivewireUploadUrlGenerator;
use App\Tenancy\TenantResolver;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Filament\Http\Middleware\AuthenticateSession as FilamentAuthenticateSession;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Facades\GenerateSignedUploadUrlFacade;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TenantResolutionTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    private TenantConnectionManager $manager;

    private string $tenantRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantRoot = $this->newTemporaryDirectory('tenant-resolution-');
        $centralDatabase = $this->tenantRoot.DIRECTORY_SEPARATOR.'central.sqlite';

        if (File::put($centralDatabase, '') === false) {
            throw new RuntimeException('Unable to create the central test database.');
        }

        config()->set('app.url', 'https://pos.example.test');
        config()->set('database.default', 'sqlite');
        config()->set('database.tenant_sqlite_root', $this->tenantRoot);
        config()->set(
            'database.tenant_attestation_lock_path',
            $this->tenantRoot.DIRECTORY_SEPARATOR.'attestation-locks',
        );
        $this->configureCentralDatabase($centralDatabase);
        DB::purge('tenant');
        $this->migrateCentralDatabase();

        $this->manager = app(TenantConnectionManager::class);
        Livewire::component('tenant-snapshot-probe', TenantSnapshotProbe::class);
        $this->registerProbeRoutes();
    }

    protected function tearDown(): void
    {
        try {
            $this->manager->disconnect();
            DB::purge('central');

            foreach ($this->temporaryDirectories as $temporaryDirectory) {
                File::deleteDirectory($temporaryDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    public function test_exact_configured_subdomain_resolves_an_active_shop_and_cleans_up_after_response(): void
    {
        $shop = $this->createTenant('alpha-shop', ShopStatus::Active);

        $this->get('https://alpha-shop.pos.example.test/__tenancy/host-probe')
            ->assertOk()
            ->assertJsonPath('shop_id', $shop->getKey())
            ->assertJsonPath(
                'upload_directory',
                'tenants/'.$shop->getKey().'/livewire-tmp',
            )
            ->assertJsonPath(
                'positional_route_url',
                'https://alpha-shop.pos.example.test/__tenants/alpha-shop/sales/123',
            );

        $this->assertNull(config('livewire.temporary_file_upload.directory'));
        $this->assertTenantStateIsRevoked();
    }

    public function test_unknown_subdomain_returns_404_without_initializing_tenancy(): void
    {
        $this->get('https://missing.pos.example.test/__tenancy/host-probe')
            ->assertNotFound()
            ->assertSee('Shop unavailable');

        $this->assertTenantStateIsRevoked();
    }

    /** @param value-of<ShopStatus> $status */
    #[DataProvider('unavailableStatuses')]
    public function test_inactive_shop_returns_503_without_exposing_connection_details(string $status): void
    {
        $password = 'database-password-sentinel-'.Str::random(12);
        $shop = $this->createTenant('closed-shop', ShopStatus::from($status), $password);

        $response = $this->get('https://closed-shop.pos.example.test/__tenancy/host-probe');

        $response
            ->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
            ->assertSee('Shop unavailable')
            ->assertDontSee($password)
            ->assertDontSee((string) $shop->database_name)
            ->assertDontSee($status);
        $this->assertTenantStateIsRevoked();
    }

    public function test_shop_suspended_between_resolution_and_connection_is_rejected_before_tenant_io(): void
    {
        $shop = $this->createTenant('suspend-during-resolution', ShopStatus::Active);
        $tenantConnections = 0;

        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use (&$tenantConnections): void {
            if ($event->connection->getName() === 'tenant') {
                $tenantConnections++;
            }
        });
        $this->app->instance(TenantResolver::class, new readonly class($shop) implements TenantResolver
        {
            public function __construct(private Shop $shop) {}

            public function resolve(Request $_request): ?Shop
            {
                $resolvedShop = Shop::query()->findOrFail($this->shop->getKey());
                $this->shop->suspend();

                return $resolvedShop;
            }
        });

        $this->get('https://suspend-during-resolution.pos.example.test/__tenancy/host-probe')
            ->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
            ->assertSee('Shop unavailable');

        $this->assertSame(0, $tenantConnections);
        $this->assertTenantStateIsRevoked();
    }

    public function test_internal_operations_can_deliberately_connect_a_provisioning_shop(): void
    {
        $shop = $this->createTenant('internal-provisioning', ShopStatus::Provisioning);

        $result = $this->manager->within($shop, function () use ($shop): string {
            $this->assertSame($shop->getKey(), app(TenantContext::class)->id());

            return 'connected';
        });

        $this->assertSame('connected', $result);
        $this->assertTenantStateIsRevoked();
    }

    /** @return array<string, array{value-of<ShopStatus>}> */
    public static function unavailableStatuses(): array
    {
        return [
            'provisioning' => [ShopStatus::Provisioning->value],
            'failed' => [ShopStatus::Failed->value],
            'suspended' => [ShopStatus::Suspended->value],
        ];
    }

    public function test_attestation_failure_returns_a_generic_503_and_revokes_tenant_state(): void
    {
        $shop = $this->createTenant('unattested-shop', ShopStatus::Active);
        $database = (string) $shop->database_name;
        $connection = DB::build($this->sqliteTestConnectionConfiguration($database));

        try {
            $connection->table('tenant_installations')->update([
                'attestation_hmac' => str_repeat('0', 64),
            ]);
        } finally {
            DB::purge($connection->getName());
        }

        $this->get('https://unattested-shop.pos.example.test/__tenancy/host-probe')
            ->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
            ->assertSee('Shop unavailable')
            ->assertDontSee($database)
            ->assertDontSee('attestation');

        $this->assertTenantStateIsRevoked();
    }

    public function test_unsigned_query_string_cannot_select_a_shop(): void
    {
        $this->createTenant('query-shop', ShopStatus::Active);

        $this->get('https://pos.example.test/__tenancy/host-probe?tenant=query-shop&shop=query-shop')
            ->assertNotFound();

        $this->assertTenantStateIsRevoked();
    }

    #[DataProvider('untrustedSelectorHeaders')]
    public function test_user_controlled_headers_cannot_select_a_shop(string $header, string $value): void
    {
        $this->createTenant('header-shop', ShopStatus::Active);

        $this->withHeader($header, $value)
            ->get('https://pos.example.test/__tenancy/host-probe')
            ->assertNotFound();

        $this->assertTenantStateIsRevoked();
    }

    /** @return array<string, array{string, string}> */
    public static function untrustedSelectorHeaders(): array
    {
        return [
            'tenant header' => ['X-Tenant', 'header-shop'],
            'shop header' => ['X-Shop', 'header-shop'],
            'forwarded host' => ['X-Forwarded-Host', 'header-shop.pos.example.test'],
            'standard forwarded host' => ['Forwarded', 'for=192.0.2.10;host=header-shop.pos.example.test'],
        ];
    }

    public function test_host_suffix_trick_cannot_select_a_shop(): void
    {
        $this->createTenant('alpha-shop', ShopStatus::Active);

        $this->get('https://alpha-shop.pos.example.test.attacker.invalid/__tenancy/host-probe')
            ->assertNotFound();

        $this->assertTenantStateIsRevoked();
    }

    public function test_route_slug_fallback_is_available_in_testing(): void
    {
        $shop = $this->createTenant('route-shop', ShopStatus::Active);

        $this->get('https://pos.example.test/__tenants/route-shop/probe')
            ->assertOk()
            ->assertJsonPath('shop_id', $shop->getKey());

        $this->assertTenantStateIsRevoked();
    }

    public function test_route_slug_fallback_is_rejected_outside_local_and_testing(): void
    {
        $this->createTenant('route-shop', ShopStatus::Active);
        config()->set('app.env', 'staging');

        $this->get('https://pos.example.test/__tenants/route-shop/probe')
            ->assertNotFound();

        $this->assertTenantStateIsRevoked();
    }

    public function test_conflicting_host_and_route_slugs_are_rejected(): void
    {
        $this->createTenant('host-shop', ShopStatus::Active);
        $this->createTenant('route-shop', ShopStatus::Active);

        $this->get('https://host-shop.pos.example.test/__tenants/route-shop/probe')
            ->assertNotFound();

        $this->assertTenantStateIsRevoked();
    }

    public function test_tenancy_is_initialized_before_implicit_model_binding(): void
    {
        $shop = $this->createTenant('binding-shop', ShopStatus::Active);
        $this->migrateTenant($shop);
        $this->manager->connect($shop);
        $item = Item::factory()->create(['name' => 'Bound from the selected shop']);
        $this->manager->disconnect();

        $this->get("https://pos.example.test/__tenants/binding-shop/items/{$item->getKey()}")
            ->assertOk()
            ->assertJsonPath('item_name', 'Bound from the selected shop');

        $this->assertTenantStateIsRevoked();
    }

    public function test_http_exception_path_cleans_up_tenant_state(): void
    {
        $this->createTenant('exception-shop', ShopStatus::Active);

        $this->get('https://exception-shop.pos.example.test/__tenancy/abort')
            ->assertStatus(Response::HTTP_I_AM_A_TEAPOT);

        $this->assertTenantStateIsRevoked();
    }

    public function test_central_request_cannot_inherit_an_existing_tenant_context(): void
    {
        $shop = $this->createTenant('ambient-shop', ShopStatus::Active);
        $this->manager->connect($shop);

        $this->get('https://pos.example.test/__central/probe')
            ->assertOk()
            ->assertJsonPath('tenant_initialized', false);

        $this->assertTenantStateIsRevoked();
    }

    public function test_a_tenant_session_cannot_authenticate_a_colliding_user_in_another_shop(): void
    {
        $shopA = $this->createTenant('session-shop-a', ShopStatus::Active);
        $shopB = $this->createTenant('session-shop-b', ShopStatus::Active);
        $this->migrateTenant($shopA);
        $this->migrateTenant($shopB);
        $userA = $this->createUser($shopA, 'shared-user');
        $userB = $this->createUser($shopB, 'shared-user');

        $this->assertSame($userA->getKey(), $userB->getKey());

        $guardSessionKey = auth('web')->getName();
        $this->withSession([
            $guardSessionKey => $userA->getKey(),
            'tenant.shop_id' => $shopA->getKey(),
        ]);

        $this->get('https://pos.example.test/__tenants/session-shop-b/session-probe')
            ->assertRedirect();

        $this->assertGuest('web');
        $this->assertTenantStateIsRevoked();
    }

    public function test_a_shop_mismatch_destroys_the_old_server_side_session_before_it_can_be_replayed(): void
    {
        $shopA = $this->createTenant('mismatch-replay-a', ShopStatus::Active);
        $shopB = $this->createTenant('mismatch-replay-b', ShopStatus::Active);
        $this->migrateTenant($shopA);
        $this->migrateTenant($shopB);
        $userA = $this->createUser($shopA, 'shared-user');
        $userB = $this->createUser($shopB, 'shared-user');

        $this->assertSame($userA->getKey(), $userB->getKey());

        $this->withSession([
            auth('web')->getName() => $userA->getKey(),
            InitializeTenancy::SESSION_SHOP_KEY => $shopA->getKey(),
        ]);
        $oldSessionId = session()->getId();
        $this->saveSessionForTenant($shopA);

        $this->withCookie((string) config('session.cookie'), $oldSessionId)
            ->get('https://pos.example.test/__tenants/mismatch-replay-b/session-probe')
            ->assertRedirect();

        $this->withCookie((string) config('session.cookie'), $oldSessionId)
            ->get('https://pos.example.test/__tenants/mismatch-replay-a/session-probe')
            ->assertRedirect();

        $this->assertGuest('web');
        $this->assertTenantStateIsRevoked();
    }

    public function test_a_tenant_remember_cookie_cannot_authenticate_a_colliding_user_in_another_shop(): void
    {
        $shopA = $this->createTenant('remember-shop-a', ShopStatus::Active);
        $shopB = $this->createTenant('remember-shop-b', ShopStatus::Active);
        $this->migrateTenant($shopA);
        $this->migrateTenant($shopB);
        $passwordHash = Hash::make('secret-password');
        $rememberToken = Str::random(60);
        $userA = $this->createUser($shopA, 'shared-user', $passwordHash, $rememberToken);
        $userB = $this->createUser($shopB, 'shared-user', $passwordHash, $rememberToken);

        $this->assertSame($userA->getKey(), $userB->getKey());

        $recallerName = auth('web')->getRecallerName();
        $loginResponse = $this->post('https://pos.example.test/__tenants/remember-shop-a/login', [
            'username' => 'shared-user',
            'password' => 'secret-password',
            'remember' => true,
        ]);
        $recaller = $loginResponse->getCookie($recallerName);

        $loginResponse->assertRedirect();
        $this->assertNotNull($recaller);

        $this->withSession([
            auth('web')->getName() => null,
            InitializeTenancy::SESSION_SHOP_KEY => $shopA->getKey(),
        ])->withCookie($recallerName, $recaller->getValue());

        $this->get('https://pos.example.test/__tenants/remember-shop-b/session-probe')
            ->assertRedirect()
            ->assertCookieExpired($recallerName);

        $this->assertGuest('web');
        $this->assertTenantStateIsRevoked();
    }

    public function test_tenant_logout_preserves_platform_session_state_and_rotates_the_session(): void
    {
        $shop = $this->createTenant('logout-shop', ShopStatus::Active);
        $this->migrateTenant($shop);
        $user = $this->createUser($shop, 'logout-user');
        $platformSessionKey = auth('platform')->getName();

        $this->withSession([
            auth('web')->getName() => $user->getKey(),
            InitializeTenancy::SESSION_SHOP_KEY => $shop->getKey(),
            $platformSessionKey => 'platform-user-id',
            'platform.support_state' => 'preserve-me',
        ]);
        $sessionId = session()->getId();

        $this->post('https://pos.example.test/__tenants/logout-shop/logout')
            ->assertRedirect()
            ->assertSessionHas($platformSessionKey, 'platform-user-id')
            ->assertSessionHas('platform.support_state', 'preserve-me')
            ->assertSessionMissing(InitializeTenancy::SESSION_SHOP_KEY);

        $this->assertNotSame($sessionId, session()->getId());
        $this->assertGuest('web');
        $this->assertTenantStateIsRevoked();
    }

    public function test_tenant_logout_destroys_the_old_server_side_session_before_it_can_be_replayed(): void
    {
        $shop = $this->createTenant('logout-replay-shop', ShopStatus::Active);
        $this->migrateTenant($shop);
        $user = $this->createUser($shop, 'logout-replay-user');

        $this->withSession([
            auth('web')->getName() => $user->getKey(),
            InitializeTenancy::SESSION_SHOP_KEY => $shop->getKey(),
        ]);
        $oldSessionId = session()->getId();
        $this->saveSessionForTenant($shop);

        $this->withCookie((string) config('session.cookie'), $oldSessionId)
            ->post('https://logout-replay-shop.pos.example.test/logout')
            ->assertRedirect();

        $this->withCookie((string) config('session.cookie'), $oldSessionId)
            ->get('https://pos.example.test/__tenants/logout-replay-shop/session-probe')
            ->assertRedirect();

        $this->assertGuest('web');
        $this->assertTenantStateIsRevoked();
    }

    public function test_filament_logout_destroys_tenant_identity_while_preserving_platform_session_state(): void
    {
        $shop = $this->createTenant('filament-logout-shop', ShopStatus::Active);
        $this->migrateTenant($shop);
        $user = $this->manager->within(
            $shop,
            static fn (): User => User::factory()->admin()->create([
                'username' => 'filament-logout-user',
                'password' => Hash::make('secret-password'),
            ]),
        );
        $platformSessionKey = auth('platform')->getName();
        $this->withSession([
            $platformSessionKey => 'platform-user-id',
            'platform.support_state' => 'preserve-me',
        ]);

        $loginResponse = $this->post('https://filament-logout-shop.pos.example.test/login', [
            'username' => $user->username,
            'password' => 'secret-password',
            'remember' => true,
        ]);
        $recallerName = auth('web')->getRecallerName();
        $oldRecaller = $loginResponse->getCookie($recallerName);
        $oldSessionId = session()->getId();
        $this->saveSessionForTenant($shop);

        $loginResponse->assertRedirect();
        $this->assertNotNull($oldRecaller);
        $this->withCookie((string) config('session.cookie'), $oldSessionId)
            ->withCookie($recallerName, $oldRecaller->getValue())
            ->post('https://filament-logout-shop.pos.example.test/admin/logout')
            ->assertRedirect()
            ->assertCookieExpired($recallerName)
            ->assertSessionHas($platformSessionKey, 'platform-user-id')
            ->assertSessionHas('platform.support_state', 'preserve-me')
            ->assertSessionMissing(auth('web')->getName())
            ->assertSessionMissing(InitializeTenancy::SESSION_SHOP_KEY);

        $this->assertNotSame($oldSessionId, session()->getId());

        $this->withCookie((string) config('session.cookie'), $oldSessionId)
            ->withCookie($recallerName, $oldRecaller->getValue())
            ->get('https://pos.example.test/__tenants/filament-logout-shop/session-probe')
            ->assertRedirect()
            ->assertSessionMissing(auth('web')->getName());

        $this->assertTenantStateIsRevoked();
    }

    public function test_login_rate_limits_are_scoped_by_shop_uuid(): void
    {
        $shopA = $this->createTenant('login-limit-a', ShopStatus::Active);
        $shopB = $this->createTenant('login-limit-b', ShopStatus::Active);
        $this->migrateTenant($shopA);
        $this->migrateTenant($shopB);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('https://pos.example.test/__tenants/login-limit-a/login', [
                'username' => 'shared-user',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('username');
        }

        $this->post('https://pos.example.test/__tenants/login-limit-b/login', [
            'username' => 'shared-user',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors([
            'username' => trans('auth.failed'),
        ]);

        $this->assertTenantStateIsRevoked();
    }

    public function test_authenticated_rate_limits_are_scoped_by_shop_uuid(): void
    {
        $shopA = $this->createTenant('route-limit-a', ShopStatus::Active);
        $shopB = $this->createTenant('route-limit-b', ShopStatus::Active);
        $userA = $this->tenantUserDescriptor($shopA);
        $userB = $this->tenantUserDescriptor($shopB);
        $route = new RoutingRoute(['GET'], '/__rate-limit-probe', static fn (): array => []);
        $middleware = app(TenantThrottleRequests::class);

        $this->manager->connect($shopA);

        try {
            $responseA = $middleware->handle(
                $this->rateLimitRequest($route, $userA),
                static fn (): Response => response('', Response::HTTP_OK),
                1,
                1,
            );
        } finally {
            $this->manager->disconnect();
        }

        $this->manager->connect($shopB);

        try {
            $responseB = $middleware->handle(
                $this->rateLimitRequest($route, $userB),
                static fn (): Response => response('', Response::HTTP_OK),
                1,
                1,
            );
        } finally {
            $this->manager->disconnect();
        }

        $this->assertSame(Response::HTTP_OK, $responseA->getStatusCode());
        $this->assertSame(Response::HTTP_OK, $responseB->getStatusCode());
    }

    public function test_tenant_middleware_precedes_auth_bindings_and_filament_boot(): void
    {
        $webMiddleware = $this->routeMiddleware('customer-vehicles.index');
        $filamentMiddleware = $this->routeMiddleware('filament.admin.pages.admin-dashboard');

        $this->assertMiddlewarePrecedes($webMiddleware, StartSession::class, EnsureShopIsActive::class);
        $this->assertMiddlewarePrecedes($webMiddleware, EnsureShopIsActive::class, InitializeTenancy::class);
        $this->assertMiddlewarePrecedes($webMiddleware, InitializeTenancy::class, Authenticate::class);
        $this->assertMiddlewarePrecedes($webMiddleware, InitializeTenancy::class, TenantThrottleRequests::class);
        $this->assertMiddlewarePrecedes($webMiddleware, InitializeTenancy::class, SubstituteBindings::class);
        $this->assertMiddlewarePrecedes($webMiddleware, InitializeTenancy::class, EnsureModuleIsEnabled::class);
        $this->assertMiddlewarePrecedes($webMiddleware, InitializeTenancy::class, PermissionMiddleware::class);
        $this->assertMiddlewarePrecedes($filamentMiddleware, StartSession::class, EnsureShopIsActive::class);
        $this->assertMiddlewarePrecedes($filamentMiddleware, EnsureShopIsActive::class, InitializeTenancy::class);
        $this->assertMiddlewarePrecedes($filamentMiddleware, InitializeTenancy::class, SetUpPanel::class);
        $this->assertMiddlewarePrecedes($filamentMiddleware, InitializeTenancy::class, FilamentAuthenticate::class);
        $this->assertMiddlewarePrecedes($filamentMiddleware, InitializeTenancy::class, FilamentAuthenticateSession::class);
        $this->assertMiddlewarePrecedes($filamentMiddleware, InitializeTenancy::class, SubstituteBindings::class);

        foreach (['filament.exports.download', 'filament.imports.failed-rows.download'] as $routeName) {
            $actionMiddleware = $this->routeMiddleware($routeName);
            $this->assertMiddlewarePrecedes(
                $actionMiddleware,
                InitializeTenancy::class,
                EnsureFilamentActionMatchesTenant::class,
            );
            $this->assertMiddlewarePrecedes(
                $actionMiddleware,
                EnsureFilamentActionMatchesTenant::class,
                SubstituteBindings::class,
            );
        }
    }

    public function test_livewire_upload_preview_and_filament_action_routes_cannot_bypass_initialization(): void
    {
        foreach ([
            Route::getRoutes()->getByName('livewire.update'),
            Route::getRoutes()->getByName('tenant.local.livewire.update'),
            Route::getRoutes()->getByName('livewire.upload-file'),
            Route::getRoutes()->getByName('tenant.local.livewire.upload-file'),
            Route::getRoutes()->getByName('livewire.preview-file'),
            Route::getRoutes()->getByName('tenant.local.livewire.preview-file'),
            Route::getRoutes()->getByName('filament.exports.download'),
            Route::getRoutes()->getByName('tenant.local.filament.exports.download'),
            Route::getRoutes()->getByName('filament.imports.failed-rows.download'),
            Route::getRoutes()->getByName('tenant.local.filament.imports.failed-rows.download'),
        ] as $route) {
            $this->assertNotNull($route);
            $middleware = array_map(
                static fn (string $name): string => Str::before($name, ':'),
                app('router')->gatherRouteMiddleware($route),
            );

            $this->assertContains(InitializeTenancy::class, $middleware);
        }

        foreach ([
            Route::getRoutes()->getByName('livewire.upload-file'),
            Route::getRoutes()->getByName('tenant.local.livewire.upload-file'),
            Route::getRoutes()->getByName('livewire.preview-file'),
            Route::getRoutes()->getByName('tenant.local.livewire.preview-file'),
        ] as $route) {
            $this->assertNotNull($route);
            $this->assertContains(
                EnsureLivewireUploadMatchesTenant::class,
                array_map(
                    static fn (string $name): string => Str::before($name, ':'),
                    app('router')->gatherRouteMiddleware($route),
                ),
            );
        }

        foreach ([
            Route::getRoutes()->getByName('filament.exports.download'),
            Route::getRoutes()->getByName('tenant.local.filament.exports.download'),
            Route::getRoutes()->getByName('filament.imports.failed-rows.download'),
            Route::getRoutes()->getByName('tenant.local.filament.imports.failed-rows.download'),
        ] as $route) {
            $this->assertNotNull($route);
            $this->assertContains(
                EnsureFilamentActionMatchesTenant::class,
                array_map(
                    static fn (string $name): string => Str::before($name, ':'),
                    app('router')->gatherRouteMiddleware($route),
                ),
            );
        }

        $this->assertContains(EnsureShopIsActive::class, Livewire::getPersistentMiddleware());
        $this->assertContains(InitializeTenancy::class, Livewire::getPersistentMiddleware());
    }

    public function test_local_package_routes_carry_the_trusted_route_selector(): void
    {
        foreach ([
            Route::getRoutes()->getByName('tenant.local.livewire.update'),
            Route::getRoutes()->getByName('tenant.local.livewire.upload-file'),
            Route::getRoutes()->getByName('tenant.local.livewire.preview-file'),
            Route::getRoutes()->getByName('tenant.local.filament.exports.download'),
            Route::getRoutes()->getByName('tenant.local.filament.imports.failed-rows.download'),
        ] as $route) {
            $this->assertNotNull($route);
            $this->assertStringStartsWith('__tenants/{tenant}/', ltrim($route->uri(), '/'));
        }

        foreach ([
            Route::getRoutes()->getByName('livewire.update'),
            Route::getRoutes()->getByName('livewire.upload-file'),
            Route::getRoutes()->getByName('livewire.preview-file'),
            Route::getRoutes()->getByName('filament.exports.download'),
            Route::getRoutes()->getByName('filament.imports.failed-rows.download'),
        ] as $route) {
            $this->assertNotNull($route);
            $this->assertStringNotContainsString('__tenants/{tenant}/', ltrim($route->uri(), '/'));
        }

        $adminRoute = Route::getRoutes()->getByName('filament.admin.pages.admin-dashboard');

        $this->assertNotNull($adminRoute);
        $this->assertSame('admin', ltrim($adminRoute->uri(), '/'));
    }

    public function test_package_route_names_are_unique_serializable_and_central_urls_need_no_tenant_default(): void
    {
        $namedRoutes = array_values(array_filter(array_map(
            static fn (RoutingRoute $route): ?string => $route->getName(),
            Route::getRoutes()->getRoutes(),
        )));
        $duplicateNames = array_keys(array_filter(
            array_count_values($namedRoutes),
            static fn (int $count): bool => $count > 1,
        ));

        $this->assertSame([], $duplicateNames);
        Route::getRoutes()->toSymfonyRouteCollection();
        $this->assertArrayNotHasKey('tenant', app('url')->getDefaultParameters());
        $this->assertSame(EndpointResolver::updatePath(), Livewire::getUpdateUri());

        $uploadUrl = GenerateSignedUploadUrlFacade::forLocal();
        $parts = parse_url($uploadUrl);
        $this->assertIsArray($parts);
        $this->assertSame(EndpointResolver::uploadPath(), $parts['path'] ?? null);
        parse_str($parts['query'] ?? '', $query);
        $this->assertSame(TenantLivewireUploadUrlGenerator::CENTRAL_CLAIM, $query['tenant_shop_id'] ?? null);
    }

    public function test_local_tenant_package_url_generation_uses_the_unique_route_fallbacks(): void
    {
        $this->createTenant('package-url-shop', ShopStatus::Active);

        $urls = $this->get('https://pos.example.test/__tenants/package-url-shop/__tenancy/package-urls')
            ->assertOk()
            ->json();

        $this->assertStringStartsWith('/__tenants/package-url-shop/'.ltrim(EndpointResolver::updatePath(), '/'), $urls['update']);
        $this->assertStringStartsWith(
            'https://pos.example.test/__tenants/package-url-shop/'.ltrim(EndpointResolver::uploadPath(), '/'),
            $urls['upload'],
        );
        $this->assertStringStartsWith(
            '/__tenants/package-url-shop/filament/exports/1/download',
            $urls['export'],
        );

        $this->assertTenantStateIsRevoked();
    }

    public function test_unknown_tenant_host_returns_branded_404_before_a_central_livewire_update(): void
    {
        $snapshotResponse = $this->get('https://pos.example.test/__central/livewire-probe');
        $snapshot = $this->extractLivewireSnapshot($snapshotResponse->getContent());
        $updateRoute = $this->livewireUpdateRoute();

        $snapshotResponse->assertOk();
        $this->assertNotNull($updateRoute);

        $this->withHeader('X-Livewire', 'true')
            ->postJson($this->hostPackageUrl($updateRoute, 'missing-shop'), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [],
                ]],
            ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Shop unavailable.');

        $this->assertTenantStateIsRevoked();
    }

    public function test_unknown_tenant_host_returns_branded_404_before_a_central_upload_can_write(): void
    {
        Storage::fake('tmp-for-tests');
        $uploadUrl = $this->replaceUrlHost(
            GenerateSignedUploadUrlFacade::forLocal(),
            'missing-shop.pos.example.test',
        );

        $this->post($uploadUrl, [
            'files' => [UploadedFile::fake()->image('unknown-host.jpg')],
        ])->assertNotFound()->assertSee('Shop unavailable');

        $this->assertSame([], Storage::disk('tmp-for-tests')->allFiles());
        $this->assertTenantStateIsRevoked();
    }

    public function test_unknown_tenant_host_returns_branded_404_before_a_central_preview_can_read(): void
    {
        Storage::fake('tmp-for-tests');
        Storage::disk('tmp-for-tests')->putFileAs(
            'livewire-tmp',
            UploadedFile::fake()->image('central-preview.jpg'),
            'central-preview.jpg',
        );
        $previewUrl = $this->replaceUrlHost(
            GenerateSignedUploadUrlFacade::signedRoute(
                'livewire.preview-file',
                now()->addMinutes(5),
                ['filename' => 'central-preview.jpg'],
            ),
            'missing-shop.pos.example.test',
        );

        $this->get($previewUrl)
            ->assertNotFound()
            ->assertSee('Shop unavailable');

        $this->assertTrue(Storage::disk('tmp-for-tests')->exists('livewire-tmp/central-preview.jpg'));
        $this->assertTenantStateIsRevoked();
    }

    #[DataProvider('filamentActionPaths')]
    public function test_unknown_tenant_host_returns_branded_404_before_filament_action_binding(string $path): void
    {
        $this->get('https://missing-shop.pos.example.test'.$path)
            ->assertNotFound()
            ->assertSee('Shop unavailable');

        $this->assertTenantStateIsRevoked();
    }

    /** @return array<string, array{string}> */
    public static function filamentActionPaths(): array
    {
        return [
            'export download' => ['/filament/exports/1/download'],
            'failed import rows download' => ['/filament/imports/1/failed-rows/download'],
        ];
    }

    #[DataProvider('filamentActionRoutes')]
    public function test_tenant_filament_action_download_is_shop_bound_and_disabled_before_record_read(
        string $action,
    ): void {
        $shopA = $this->createTenant('filament-action-a', ShopStatus::Active);
        $shopB = $this->createTenant('filament-action-b', ShopStatus::Active);
        config()->set('app.env', 'production');
        Storage::fake('local');

        $generatedUrl = $this->get(
            "https://filament-action-a.pos.example.test/__tenancy/filament-action-url/{$action}",
        )->assertOk()->json('url');

        $this->assertIsString($generatedUrl);
        $this->assertStringContainsString($shopA->getKey(), $generatedUrl);
        $replayedUrl = $this->replaceUrlHost($generatedUrl, 'filament-action-b.pos.example.test');
        $this->assertTrue(Request::create($replayedUrl)->hasValidRelativeSignature());
        $filamentRecordQueries = 0;
        DB::listen(static function (QueryExecuted $event) use (&$filamentRecordQueries): void {
            if (preg_match('/\b(exports|imports|failed_import_rows)\b/i', $event->sql) === 1) {
                $filamentRecordQueries++;
            }
        });

        $this->get($replayedUrl)
            ->assertNotFound()
            ->assertSee('Shop unavailable');
        $this->get($this->replaceUrlHost($generatedUrl, 'filament-action-a.pos.example.test'))
            ->assertNotFound()
            ->assertSee('Shop unavailable');

        $this->assertSame(0, $filamentRecordQueries);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertTenantStateIsRevoked();
    }

    /** @return array<string, array{string}> */
    public static function filamentActionRoutes(): array
    {
        return [
            'export download' => ['export'],
            'failed import rows download' => ['import'],
        ];
    }

    public function test_local_livewire_update_and_upload_requests_initialize_and_revoke_tenancy(): void
    {
        $this->createTenant('local-livewire-shop', ShopStatus::Active);
        $tenantConnections = 0;

        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use (&$tenantConnections): void {
            if ($event->connection->getName() === 'tenant') {
                $tenantConnections++;
            }
        });

        $updateRoute = Route::getRoutes()->getByName('tenant.local.livewire.update');
        $uploadRoute = Route::getRoutes()->getByName('tenant.local.livewire.upload-file');

        $this->assertNotNull($updateRoute);
        $this->assertNotNull($uploadRoute);

        $this->withHeader('X-Livewire', 'true')
            ->postJson($this->tenantPackageUrl($updateRoute, 'local-livewire-shop'), ['components' => []])
            ->assertNotFound();

        $this->post($this->tenantPackageUrl($uploadRoute, 'local-livewire-shop'))
            ->assertNotFound();

        $this->assertGreaterThanOrEqual(2, $tenantConnections);
        $this->assertTenantStateIsRevoked();
    }

    public function test_actual_livewire_failure_cleans_up_tenant_state(): void
    {
        $this->createTenant('livewire-shop', ShopStatus::Active);
        $updateRoute = $this->livewireUpdateRoute();
        $tenantConnections = 0;

        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use (&$tenantConnections): void {
            if ($event->connection->getName() === 'tenant') {
                $tenantConnections++;
            }
        });

        $this->assertNotNull($updateRoute);

        $this->withHeader('X-Livewire', 'true')
            ->postJson($this->hostPackageUrl($updateRoute, 'livewire-shop'), ['components' => []])
            ->assertNotFound();

        $this->assertGreaterThanOrEqual(1, $tenantConnections);
        $this->assertTenantStateIsRevoked();
    }

    public function test_a_livewire_snapshot_from_one_host_is_rejected_on_another_host(): void
    {
        $shopA = $this->createTenant('snapshot-shop-a', ShopStatus::Active);
        $shopB = $this->createTenant('snapshot-shop-b', ShopStatus::Active);
        $response = $this->get('https://snapshot-shop-a.pos.example.test/__tenancy/livewire-probe');
        $snapshot = $this->extractLivewireSnapshot($response->getContent());
        $updateRoute = $this->livewireUpdateRoute();

        $response->assertOk();
        $this->assertNotNull($updateRoute);
        $this->assertStringContainsString($shopA->getKey(), $snapshot);
        $this->withSession([InitializeTenancy::SESSION_SHOP_KEY => $shopB->getKey()]);

        $this->withHeader('X-Livewire', 'true')
            ->postJson($this->hostPackageUrl($updateRoute, $shopB->slug), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [],
                ]],
            ])
            ->assertNotFound();

        $this->assertTenantStateIsRevoked();
    }

    public function test_a_livewire_upload_url_from_one_host_cannot_be_replayed_on_another_host(): void
    {
        $shopA = $this->createTenant('upload-url-shop-a', ShopStatus::Active);
        $shopB = $this->createTenant('upload-url-shop-b', ShopStatus::Active);
        Storage::fake('tmp-for-tests');

        $generatedUrl = $this->get('https://upload-url-shop-a.pos.example.test/__tenancy/livewire-upload-url')
            ->assertOk()
            ->json('url');

        $this->assertIsString($generatedUrl);
        $url = parse_url($generatedUrl);
        $this->assertIsArray($url);
        $replayedUrl = 'https://upload-url-shop-b.pos.example.test'.($url['path'] ?? '/');

        if (isset($url['query'])) {
            $replayedUrl .= '?'.$url['query'];
        }

        $this->assertTrue(Request::create($replayedUrl, 'POST')->hasValidRelativeSignature());

        $this->post($replayedUrl, [
            'files' => [UploadedFile::fake()->image('receipt.jpg')],
        ])->assertNotFound();

        parse_str($url['query'] ?? '', $query);
        $this->assertSame(
            $shopA->getKey(),
            $query[TenantLivewireUploadUrlGenerator::TENANT_CLAIM] ?? null,
        );

        $query[TenantLivewireUploadUrlGenerator::TENANT_CLAIM] = $shopB->getKey();
        $forgedUrl = 'https://upload-url-shop-b.pos.example.test'.($url['path'] ?? '/').'?'.http_build_query($query);
        $this->assertFalse(Request::create($forgedUrl, 'POST')->hasValidRelativeSignature());
        $this->post($forgedUrl, [
            'files' => [UploadedFile::fake()->image('forged-receipt.jpg')],
        ])->assertUnauthorized();

        $this->assertInstanceOf(
            TenantLivewireUploadUrlGenerator::class,
            GenerateSignedUploadUrlFacade::getFacadeRoot(),
        );
        $this->assertInstanceOf(
            GenerateSignedUploadUrl::class,
            GenerateSignedUploadUrlFacade::getFacadeRoot(),
        );
        $this->assertSame([], Storage::disk('tmp-for-tests')->allFiles());
        $this->assertTenantStateIsRevoked();
    }

    private function registerProbeRoutes(): void
    {
        Route::middleware(['web', 'shop.active', 'tenant'])
            ->get('/__tenancy/host-probe', function (TenantContext $context): array {
                return [
                    'shop_id' => $context->id(),
                    'upload_directory' => config('livewire.temporary_file_upload.directory'),
                    'positional_route_url' => route('sales.show', 123),
                ];
            })
            ->name('tenancy.host-probe');

        Route::middleware(['web', 'shop.active', 'tenant'])
            ->get('/__tenancy/abort', static function (): never {
                abort(Response::HTTP_I_AM_A_TEAPOT);
            })
            ->name('tenancy.abort');

        Route::middleware(['web', 'shop.active', 'tenant'])
            ->get(
                '/__tenancy/livewire-probe',
                static fn (): string => Livewire::mount('tenant-snapshot-probe'),
            )
            ->name('tenancy.livewire-probe');

        Route::middleware(['web', 'shop.active', 'tenant'])
            ->get('/__tenancy/livewire-upload-url', static fn (): array => [
                'url' => GenerateSignedUploadUrlFacade::signedRoute(
                    'tenancy.livewire-upload-probe',
                    now()->addMinutes(5),
                ),
            ])
            ->name('tenancy.livewire-upload-url');

        Route::middleware(['web', 'shop.active', 'tenant'])
            ->get('/__tenants/{tenant}/__tenancy/package-urls', static fn (): array => [
                'update' => Livewire::getUpdateUri(),
                'upload' => GenerateSignedUploadUrlFacade::forLocal(),
                'export' => URL::signedRoute('filament.exports.download', [
                    'authGuard' => 'web',
                    'export' => 1,
                    'format' => 'csv',
                ], absolute: false),
            ])
            ->name('tenancy.package-urls');

        Route::middleware(['web', 'shop.active', 'tenant'])
            ->get('/__tenancy/filament-action-url/{action}', static function (string $action): array {
                [$routeName, $parameters] = match ($action) {
                    'export' => ['filament.exports.download', [
                        'authGuard' => 'web',
                        'export' => 1,
                        'format' => 'csv',
                    ]],
                    'import' => ['filament.imports.failed-rows.download', [
                        'authGuard' => 'web',
                        'import' => 1,
                    ]],
                    default => abort(Response::HTTP_NOT_FOUND),
                };

                return ['url' => URL::signedRoute($routeName, $parameters, absolute: false)];
            })
            ->name('tenancy.filament-action-url');

        Route::post('/__tenancy/livewire-upload', [FileUploadController::class, 'handle'])
            ->name('tenancy.livewire-upload-probe');

        Route::middleware(['web', 'shop.active', 'tenant'])
            ->get('/__tenants/{tenant}/probe', function (TenantContext $context): array {
                return ['shop_id' => $context->id()];
            })
            ->name('tenancy.route-probe');

        Route::middleware(['web', 'shop.active', 'tenant'])
            ->get('/__tenants/{tenant}/items/{item}', static function (Item $item): array {
                return ['item_name' => $item->name];
            })
            ->name('tenancy.binding-probe');

        Route::middleware(['web', 'shop.active', 'tenant', 'auth'])
            ->get('/__tenants/{tenant}/auth-probe', static fn (): array => ['authenticated' => true])
            ->name('tenancy.auth-probe');

        Route::middleware(['web', 'shop.active', 'tenant', 'auth'])
            ->get('/__tenants/{tenant}/session-probe', static fn (): array => [
                'user_id' => auth()->id(),
            ])
            ->name('tenancy.session-probe');

        Route::middleware('web')
            ->get('/__central/probe', static function (TenantContext $context): array {
                return ['tenant_initialized' => $context->initialized()];
            })
            ->name('central.probe');

        Route::middleware('web')
            ->get(
                '/__central/livewire-probe',
                static fn (): string => Livewire::mount('tenant-snapshot-probe'),
            )
            ->name('central.livewire-probe');

        Route::getRoutes()->refreshNameLookups();
    }

    private function livewireUpdateRoute(): ?RoutingRoute
    {
        return Route::getRoutes()->getByName('livewire.update');
    }

    private function createTenant(
        string $slug,
        ShopStatus $status,
        #[\SensitiveParameter]
        ?string $databasePassword = null,
    ): Shop {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.$slug.'.sqlite';

        $shop = Shop::registerForProvisioning(
            name: str($slug)->headline()->toString(),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $database,
            databasePassword: $databasePassword,
        );
        $this->createTenantDatabase($shop);

        match ($status) {
            ShopStatus::Provisioning => null,
            ShopStatus::Active => $shop->markActive(),
            ShopStatus::Failed => $shop->markProvisioningFailed('Provisioning could not finish.'),
            ShopStatus::Suspended => $this->suspend($shop),
        };

        return $shop;
    }

    private function suspend(Shop $shop): void
    {
        $shop->markActive();
        $shop->suspend();
    }

    private function createUser(
        Shop $shop,
        string $username,
        ?string $passwordHash = null,
        ?string $rememberToken = null,
    ): User {
        return $this->manager->within(
            $shop,
            static fn (): User => User::factory()->create(array_filter([
                'username' => $username,
                'password' => $passwordHash ?? Hash::make('secret-password'),
                'remember_token' => $rememberToken,
            ], static fn (mixed $value): bool => $value !== null)),
        );
    }

    private function tenantPackageUrl(RoutingRoute $route, string $slug): string
    {
        $uri = str_replace('{tenant}', $slug, $route->uri());

        return 'https://pos.example.test/'.ltrim($uri, '/');
    }

    private function hostPackageUrl(RoutingRoute $route, string $slug): string
    {
        $uri = str_replace('__tenants/{tenant}/', '', $route->uri());

        return "https://{$slug}.pos.example.test/".ltrim($uri, '/');
    }

    private function replaceUrlHost(string $url, string $host): string
    {
        $parts = parse_url($url);
        $this->assertIsArray($parts);
        $url = 'https://'.$host.($parts['path'] ?? '/');

        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }

        return $url;
    }

    private function extractLivewireSnapshot(string $html): string
    {
        $matched = preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);

        $this->assertSame(1, $matched, 'The rendered component did not contain a Livewire snapshot.');

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    }

    private function tenantUserDescriptor(Shop $shop): User
    {
        $this->manager->connect($shop);

        try {
            $user = new User;
            $user->setRawAttributes(['id' => 1, 'username' => 'shared-user'], true);
            $user->exists = true;

            return $user;
        } finally {
            $this->manager->disconnect();
        }
    }

    private function rateLimitRequest(RoutingRoute $route, User $user): Request
    {
        $request = Request::create('/__rate-limit-probe');
        $request->setRouteResolver(static fn (): RoutingRoute => $route);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }

    private function migrateTenant(Shop $shop): void
    {
        $this->migrateTenantDatabase($shop);
    }

    private function saveSessionForTenant(Shop $shop): void
    {
        $this->manager->within($shop, static function (): void {
            session()->save();
        });
    }

    /** @return list<string> */
    private function routeMiddleware(string $routeName): array
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route);

        return app('router')->gatherRouteMiddleware($route);
    }

    /** @param list<string> $middleware */
    private function assertMiddlewarePrecedes(array $middleware, string $first, string $second): void
    {
        $middleware = array_map(
            static fn (string $name): string => Str::before($name, ':'),
            $middleware,
        );
        $firstPosition = array_search($first, $middleware, true);
        $secondPosition = array_search($second, $middleware, true);

        $this->assertIsInt($firstPosition, "{$first} is missing from the route middleware.");
        $this->assertIsInt($secondPosition, "{$second} is missing from the route middleware.");
        $this->assertLessThan($secondPosition, $firstPosition, "{$first} must run before {$second}.");
    }

    private function assertTenantStateIsRevoked(): void
    {
        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
        $this->assertArrayNotHasKey('tenant', DB::getConnections());
    }

    private function newTemporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.Str::uuid();

        if (! File::makeDirectory($directory, 0700, true)) {
            throw new RuntimeException('Unable to create tenant resolution test directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}

final class TenantSnapshotProbe extends Component
{
    public string $shopId = '';

    public function mount(TenantContext $context): void
    {
        $this->shopId = $context->initialized() ? $context->id() : 'central';
    }

    public function render(): string
    {
        return '<div>'.$this->shopId.'</div>';
    }
}
