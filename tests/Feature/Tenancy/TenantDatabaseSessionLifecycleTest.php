<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\EnsureShopIsActive;
use App\Http\Middleware\InitializeTenancy;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\User;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('database-session')]
class TenantDatabaseSessionLifecycleTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    private TenantConnectionManager $manager;

    private string $tenantRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantRoot = $this->newTemporaryDirectory('tenant-database-session-');
        $centralDatabase = $this->tenantRoot.DIRECTORY_SEPARATOR.'central.sqlite';

        if (File::put($centralDatabase, '') === false) {
            throw new RuntimeException('Unable to create the central test database.');
        }

        config()->set('app.url', 'https://pos.example.test');
        config()->set('database.default', 'central');
        config()->set('database.tenant_sqlite_root', $this->tenantRoot);
        config()->set(
            'database.tenant_attestation_lock_path',
            $this->tenantRoot.DIRECTORY_SEPARATOR.'attestation-locks',
        );
        $this->configureCentralDatabase($centralDatabase);
        DB::purge('tenant');
        $this->migrateCentralDatabase();

        config()->set('session.driver', 'database');
        config()->set('session.connection', 'central');
        config()->set('session.lottery', [0, 100]);
        $this->resetResolvedSessionAndGuards();

        $this->manager = app(TenantConnectionManager::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->manager->disconnect();
            $this->resetResolvedSessionAndGuards();
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

    public function test_database_session_login_persists_and_authenticates_with_a_fresh_guard(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-login');

        $loginResponse = $this->post($this->tenantUrl($shop, '/login'), [
            'username' => $user->username,
            'password' => 'secret-password',
        ]);

        $loginResponse
            ->assertRedirect()
            ->assertSessionHas(InitializeTenancy::SESSION_SHOP_KEY, $shop->getKey());
        $sessionId = $loginResponse->baseRequest->session()->getId();
        $this->assertDatabaseHas('sessions', [
            'id' => $sessionId,
            'user_id' => $user->getKey(),
        ], 'central');
        $this->assertTenantStateIsRevoked();

        $this->resetResolvedSessionAndGuards();

        $this->withCredentials()
            ->withCookie((string) config('session.cookie'), $sessionId)
            ->getJson($this->tenantUrl($shop, '/quick-items'))
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->assertTenantStateIsRevoked();
    }

    public function test_authenticated_post_persists_domain_and_database_session_state(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-post');
        $loginResponse = $this->post($this->tenantUrl($shop, '/login'), [
            'username' => $user->username,
            'password' => 'secret-password',
        ])->assertRedirect();
        $sessionId = $loginResponse->baseRequest->session()->getId();
        $this->assertTenantStateIsRevoked();
        $this->resetResolvedSessionAndGuards();

        $postResponse = $this->withCredentials()
            ->withCookie((string) config('session.cookie'), $sessionId)
            ->postJson($this->tenantUrl($shop, '/quick-items'), [
                'name' => 'Database Session Service',
                'type' => 'repair',
            ]);

        $postResponse
            ->assertCreated()
            ->assertJsonPath('data.name', 'Database Session Service');
        $this->assertSame($sessionId, $postResponse->baseRequest->session()->getId());
        $this->assertDatabaseHas('sessions', [
            'id' => $sessionId,
            'user_id' => $user->getKey(),
        ], 'central');
        $this->manager->within($shop, function (): void {
            $this->assertDatabaseHas('items', [
                'name' => 'Database Session Service',
                'type' => 'repair',
            ], 'tenant');
        });
        $this->assertTenantStateIsRevoked();

        $this->resetResolvedSessionAndGuards();

        $this->withCredentials()
            ->withCookie((string) config('session.cookie'), $sessionId)
            ->getJson($this->tenantUrl($shop, '/quick-items'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Database Session Service');
        $this->assertTenantStateIsRevoked();
    }

    public function test_tenant_logout_preserves_a_coexisting_platform_database_session(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-logout');
        $platformUser = PlatformUser::factory()->create();
        $platformGuard = Auth::guard('platform');
        $platformGuard->login($platformUser);
        $platformSessionKey = $platformGuard->getName();
        session()->save();
        $platformSessionId = session()->getId();
        $this->resetResolvedSessionAndGuards();

        $loginResponse = $this->withCookie((string) config('session.cookie'), $platformSessionId)
            ->post($this->tenantUrl($shop, '/login'), [
                'username' => $user->username,
                'password' => 'secret-password',
            ]);

        $loginResponse
            ->assertRedirect()
            ->assertSessionHas($platformSessionKey, $platformUser->getKey());
        $tenantSessionId = $loginResponse->baseRequest->session()->getId();
        $this->assertNotSame($platformSessionId, $tenantSessionId);
        $this->assertTenantStateIsRevoked();
        $this->resetResolvedSessionAndGuards();

        $logoutResponse = $this->withCookie((string) config('session.cookie'), $tenantSessionId)
            ->post($this->tenantUrl($shop, '/logout'));

        $logoutResponse
            ->assertRedirect($this->tenantUrl($shop, '/login'))
            ->assertSessionHas($platformSessionKey, $platformUser->getKey())
            ->assertSessionMissing(Auth::guard('web')->getName())
            ->assertSessionMissing(InitializeTenancy::SESSION_SHOP_KEY);
        $loggedOutSessionId = $logoutResponse->baseRequest->session()->getId();
        $this->assertNotSame($tenantSessionId, $loggedOutSessionId);
        $this->assertDatabaseMissing('sessions', ['id' => $tenantSessionId], 'central');
        $this->assertDatabaseHas('sessions', ['id' => $loggedOutSessionId], 'central');
        $this->assertTenantStateIsRevoked();
        $this->resetResolvedSessionAndGuards();

        $this->withCookie((string) config('session.cookie'), $loggedOutSessionId)
            ->get('/platform')
            ->assertOk();
        $this->assertAuthenticatedAs($platformUser, 'platform');
        $this->assertGuest('web');
        $this->assertTenantStateIsRevoked();
    }

    public function test_database_session_starts_before_tenant_binding_and_authentication(): void
    {
        $webMiddleware = $this->routeMiddleware('quick-items.store');
        $filamentMiddleware = $this->routeMiddleware('filament.admin.pages.admin-dashboard');

        $this->assertMiddlewarePrecedes($webMiddleware, StartSession::class, EnsureShopIsActive::class);
        $this->assertMiddlewarePrecedes($webMiddleware, EnsureShopIsActive::class, InitializeTenancy::class);
        $this->assertMiddlewarePrecedes($webMiddleware, InitializeTenancy::class, Authenticate::class);
        $this->assertMiddlewarePrecedes($webMiddleware, InitializeTenancy::class, SubstituteBindings::class);
        $this->assertMiddlewarePrecedes($filamentMiddleware, StartSession::class, EnsureShopIsActive::class);
        $this->assertMiddlewarePrecedes($filamentMiddleware, EnsureShopIsActive::class, InitializeTenancy::class);
        $this->assertMiddlewarePrecedes($filamentMiddleware, InitializeTenancy::class, SetUpPanel::class);
        $this->assertMiddlewarePrecedes($filamentMiddleware, InitializeTenancy::class, FilamentAuthenticate::class);
    }

    /** @return array{Shop, User} */
    private function createActiveTenantWithManager(string $slug): array
    {
        $shop = Shop::registerForProvisioning(
            name: str($slug)->headline()->toString(),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $this->tenantRoot.DIRECTORY_SEPARATOR.$slug.'.sqlite',
        );
        $this->createMigratedTenantDatabase($shop);
        $shop->markActive();

        $user = $this->manager->within(
            $shop,
            static fn (): User => User::factory()->manager()->create([
                'username' => $slug.'-manager',
                'password' => Hash::make('secret-password'),
            ]),
        );

        return [$shop, $user];
    }

    private function tenantUrl(Shop $shop, string $path): string
    {
        return 'https://pos.example.test/__tenants/'.$shop->slug.'/'.ltrim($path, '/');
    }

    private function resetResolvedSessionAndGuards(): void
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        app(SessionManager::class)->forgetDrivers();
        $this->app->forgetInstance('session.store');
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
            throw new RuntimeException('Unable to create tenant database-session test directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
