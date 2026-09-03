<?php

namespace Tests\Feature\Tenancy;

use App\Actions\ManageTenantUsers;
use App\Enums\Role;
use App\Http\Middleware\EnsureShopIsActive;
use App\Http\Middleware\InitializeTenancy;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\User;
use App\Support\TenantSessionAuthentication;
use App\Tenancy\SupportAccessManager;
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
use Illuminate\Support\Facades\Event;
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

    public function test_explicit_login_records_the_tenant_authentication_generation(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-login-generation');

        $this->post($this->tenantUrl($shop, '/login'), [
            'username' => $user->username,
            'password' => 'secret-password',
        ])->assertRedirect()->assertSessionHas(
            TenantSessionAuthentication::GENERATION_SESSION_KEY,
            hash_hmac('sha256', (string) $user->getRememberToken(), (string) config('app.key')),
        );

        $this->assertTenantStateIsRevoked();
    }

    public function test_valid_remember_cookie_restores_after_database_session_expiry_and_records_generation(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-remember-generation');
        $webGuard = Auth::guard('web');
        $recallerName = $webGuard->getRecallerName();
        $tenantBindingName = $recallerName.'_tenant';
        $loginResponse = $this->post($this->tenantUrl($shop, '/login'), [
            'username' => $user->username,
            'password' => 'secret-password',
            'remember' => true,
        ])->assertRedirect();
        $recaller = $loginResponse->getCookie($recallerName);
        $tenantBinding = $loginResponse->getCookie($tenantBindingName);
        $this->assertNotNull($recaller);
        $this->assertNotNull($tenantBinding);
        $tenantBindingPayload = json_decode($tenantBinding->getValue(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($shop->getKey(), $tenantBindingPayload['shop_id']);
        $this->assertSame(
            hash_hmac('sha256', $recaller->getValue(), (string) config('app.key')),
            $tenantBindingPayload['recaller_digest'],
        );
        $expiredSessionId = $loginResponse->baseRequest->session()->getId();
        DB::connection('central')->table('sessions')->where('id', $expiredSessionId)->delete();
        $this->resetResolvedSessionAndGuards();

        $response = $this
            ->withCredentials()
            ->withCookies([
                $recallerName => $recaller->getValue(),
                $tenantBindingName => $tenantBinding->getValue(),
            ])
            ->getJson($this->tenantUrl($shop, '/quick-items'));

        $response
            ->assertOk()
            ->assertSessionHas($webGuard->getName(), $user->getKey())
            ->assertSessionHas(
                TenantSessionAuthentication::GENERATION_SESSION_KEY,
                hash_hmac('sha256', (string) $user->getRememberToken(), (string) config('app.key')),
            );
        $this->assertTenantStateIsRevoked();
    }

    public function test_remember_cookie_cannot_be_replayed_across_tenants_after_database_session_expiry(): void
    {
        [$originShop, $originUser] = $this->createActiveTenantWithManager('database-remember-origin');
        [$otherShop, $otherUser] = $this->createActiveTenantWithManager('database-remember-other');
        $webGuard = Auth::guard('web');
        $recallerName = $webGuard->getRecallerName();
        $tenantBindingName = $recallerName.'_tenant';
        $loginResponse = $this->post($this->tenantUrl($originShop, '/login'), [
            'username' => $originUser->username,
            'password' => 'secret-password',
            'remember' => true,
        ])->assertRedirect();
        $recaller = $loginResponse->getCookie($recallerName);
        $tenantBinding = $loginResponse->getCookie($tenantBindingName);
        $this->assertNotNull($recaller);
        $this->assertNotNull($tenantBinding);
        $expiredSessionId = $loginResponse->baseRequest->session()->getId();
        $sharedCredentials = $this->manager->within(
            $originShop,
            static function () use ($originUser): array {
                $freshUser = User::query()->findOrFail($originUser->getKey());

                return [
                    'id' => $freshUser->getKey(),
                    'password' => $freshUser->getAuthPassword(),
                    'remember_token' => $freshUser->getRememberToken(),
                ];
            },
        );
        $this->manager->within($otherShop, function () use ($otherUser, $sharedCredentials): void {
            $collidingUser = User::query()->findOrFail($otherUser->getKey());
            $this->assertSame((string) $sharedCredentials['id'], (string) $collidingUser->getKey());
            $collidingUser->forceFill(['password' => $sharedCredentials['password']]);
            $collidingUser->setRememberToken($sharedCredentials['remember_token']);
            $collidingUser->save();
        });
        DB::connection('central')->table('sessions')->where('id', $expiredSessionId)->delete();
        $this->resetResolvedSessionAndGuards();

        $response = $this
            ->withCookies([
                $recallerName => $recaller->getValue(),
                $tenantBindingName => $tenantBinding->getValue(),
            ])
            ->get($this->tenantUrl($otherShop, '/quick-items'));

        $response
            ->assertRedirect($this->tenantUrl($otherShop, '/login'))
            ->assertCookieExpired($recallerName)
            ->assertCookieExpired($tenantBindingName)
            ->assertSessionMissing($webGuard->getName())
            ->assertSessionMissing(TenantSessionAuthentication::GENERATION_SESSION_KEY);
        $this->assertTenantStateIsRevoked();
    }

    public function test_deactivated_authenticated_tenant_user_is_denied_on_the_next_request(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-deactivated-user');
        $actorId = $this->manager->within(
            $shop,
            static fn (): int => (int) User::factory()->admin()->create()->getKey(),
        );
        $loginResponse = $this->post($this->tenantUrl($shop, '/login'), [
            'username' => $user->username,
            'password' => 'secret-password',
        ])->assertRedirect();
        $sessionId = $loginResponse->baseRequest->session()->getId();
        $this->resetResolvedSessionAndGuards();

        $this->manager->within($shop, static function () use ($actorId, $user): void {
            resolve(ManageTenantUsers::class)->update(
                User::query()->findOrFail($actorId),
                User::query()->findOrFail($user->getKey()),
                ['is_active' => false],
                Role::Manager->value,
            );
        });
        $this->resetResolvedSessionAndGuards();

        $response = $this->withCookie((string) config('session.cookie'), $sessionId)
            ->get($this->tenantUrl($shop, '/quick-items'))
            ->assertRedirect($this->tenantUrl($shop, '/login'));
        $response
            ->assertSessionMissing(Auth::guard('web')->getName())
            ->assertSessionMissing(TenantSessionAuthentication::GENERATION_SESSION_KEY)
            ->assertSessionMissing(InitializeTenancy::SESSION_SHOP_KEY);
        $this->assertTenantStateIsRevoked();
    }

    public function test_old_tenant_remember_cookie_cannot_authenticate_after_reactivation(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-reactivated-remember');
        $actorId = $this->manager->within(
            $shop,
            static fn (): int => (int) User::factory()->admin()->create()->getKey(),
        );
        $webGuard = Auth::guard('web');
        $recallerName = $webGuard->getRecallerName();
        $loginResponse = $this->post($this->tenantUrl($shop, '/login'), [
            'username' => $user->username,
            'password' => 'secret-password',
            'remember' => true,
        ])->assertRedirect();
        $oldRecaller = $loginResponse->getCookie($recallerName);
        $tenantBindingName = $recallerName.'_tenant';
        $tenantBinding = $loginResponse->getCookie($tenantBindingName);
        $this->assertNotNull($oldRecaller);
        $this->assertNotNull($tenantBinding);
        $this->resetResolvedSessionAndGuards();

        $this->manager->within($shop, static function () use ($actorId, $user): void {
            $action = resolve(ManageTenantUsers::class);
            $actor = User::query()->findOrFail($actorId);
            $record = User::query()->findOrFail($user->getKey());
            $action->update($actor, $record, ['is_active' => false], Role::Manager->value);
            $action->update($actor, $record, ['is_active' => true], Role::Manager->value);
        });
        $this->resetResolvedSessionAndGuards();
        $this->withSession([
            Auth::guard('web')->getName() => null,
            InitializeTenancy::SESSION_SHOP_KEY => $shop->getKey(),
        ]);

        $this->withCookies([
            $recallerName => $oldRecaller->getValue(),
            $tenantBindingName => $tenantBinding->getValue(),
        ])
            ->get($this->tenantUrl($shop, '/quick-items'))
            ->assertRedirect($this->tenantUrl($shop, '/login'))
            ->assertCookieExpired($recallerName)
            ->assertCookieExpired($tenantBindingName);
        $this->assertTenantStateIsRevoked();
    }

    public function test_direct_reactivation_of_a_legacy_inactive_user_revokes_existing_credentials(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-legacy-inactive-reactivation');
        $actorId = $this->manager->within(
            $shop,
            static fn (): int => (int) User::factory()->admin()->create()->getKey(),
        );
        $webGuard = Auth::guard('web');
        $recallerName = $webGuard->getRecallerName();
        $loginResponse = $this->post($this->tenantUrl($shop, '/login'), [
            'username' => $user->username,
            'password' => 'secret-password',
            'remember' => true,
        ])->assertRedirect();
        $oldRecaller = $loginResponse->getCookie($recallerName);
        $this->assertNotNull($oldRecaller);
        $oldRememberToken = $this->manager->within(
            $shop,
            static fn (): ?string => User::query()->findOrFail($user->getKey())->getRememberToken(),
        );
        $this->resetResolvedSessionAndGuards();

        $this->manager->within($shop, static function () use ($user): void {
            User::query()->whereKey($user->getKey())->update(['is_active' => false]);
        });
        $sessionId = 'legacy-inactive-user-session';
        DB::connection('central')->table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => $this->encodeSessionPayload([
                '_token' => 'csrf-token',
                $webGuard->getName() => $user->getKey(),
                TenantSessionAuthentication::GENERATION_SESSION_KEY => hash_hmac(
                    'sha256',
                    (string) $oldRememberToken,
                    (string) config('app.key'),
                ),
                InitializeTenancy::SESSION_SHOP_KEY => $shop->getKey(),
            ]),
            'last_activity' => now()->timestamp,
        ]);

        $this->manager->within($shop, static function () use ($actorId, $user): void {
            resolve(ManageTenantUsers::class)->update(
                User::query()->findOrFail($actorId),
                User::query()->findOrFail($user->getKey()),
                ['is_active' => true],
                Role::Manager->value,
            );
        });

        $newRememberToken = $this->manager->within(
            $shop,
            static fn (): ?string => User::query()->findOrFail($user->getKey())->getRememberToken(),
        );
        $this->assertNotSame($oldRememberToken, $newRememberToken);
        $payload = $this->persistedSessionPayload($sessionId);
        $this->assertArrayNotHasKey($webGuard->getName(), $payload);
        $this->assertArrayNotHasKey(TenantSessionAuthentication::GENERATION_SESSION_KEY, $payload);
        $this->assertArrayNotHasKey(InitializeTenancy::SESSION_SHOP_KEY, $payload);
        $this->resetResolvedSessionAndGuards();
        $this->withSession([InitializeTenancy::SESSION_SHOP_KEY => $shop->getKey()]);

        $this->withCookie($recallerName, $oldRecaller->getValue())
            ->get($this->tenantUrl($shop, '/quick-items'))
            ->assertRedirect($this->tenantUrl($shop, '/login'));
        $this->assertTenantStateIsRevoked();
    }

    public function test_deactivation_removes_only_matching_tenant_authentication_from_persisted_sessions(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-session-revocation');
        $actorId = $this->manager->within(
            $shop,
            static fn (): int => (int) User::factory()->admin()->create()->getKey(),
        );
        $webLoginKey = Auth::guard('web')->getName();
        $platformLoginKey = Auth::guard('platform')->getName();
        $matchingSessionId = 'matching-tenant-user-session';
        $matchingPayload = [
            '_token' => 'csrf-token',
            $webLoginKey => $user->getKey(),
            'auth_generation_web' => 'stale-generation',
            'password_hash_web' => 'password-hash',
            InitializeTenancy::SESSION_SHOP_KEY => $shop->getKey(),
            $platformLoginKey => 'platform-admin-id',
            'auth_generation_platform' => 'platform-generation',
            'password_hash_platform' => 'platform-password-hash',
            SupportAccessManager::SESSION_KEY => [
                'audit_id' => 'support-audit-id',
                'shop_id' => $shop->getKey(),
            ],
            'tenant.checkout.draft' => ['sale_id' => 91],
        ];
        $differentShopPayload = array_replace($matchingPayload, [
            InitializeTenancy::SESSION_SHOP_KEY => 'different-shop-id',
        ]);
        $differentUserPayload = array_replace($matchingPayload, [$webLoginKey => 999]);
        $malformedUserPayload = array_replace($matchingPayload, [$webLoginKey => ['unexpected']]);

        foreach ([
            $matchingSessionId => $this->encodeSessionPayload($matchingPayload),
            'different-shop-session' => $this->encodeSessionPayload($differentShopPayload),
            'different-user-session' => $this->encodeSessionPayload($differentUserPayload),
            'malformed-user-session' => $this->encodeSessionPayload($malformedUserPayload),
            'malformed-session' => 'not-base64',
        ] as $sessionId => $payload) {
            DB::connection('central')->table('sessions')->insert([
                'id' => $sessionId,
                'user_id' => $user->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => $payload,
                'last_activity' => now()->timestamp,
            ]);
        }

        $this->manager->within($shop, static function () use ($actorId, $user): void {
            resolve(ManageTenantUsers::class)->update(
                User::query()->findOrFail($actorId),
                User::query()->findOrFail($user->getKey()),
                ['is_active' => false],
                Role::Manager->value,
            );
        });

        $payload = $this->persistedSessionPayload($matchingSessionId);
        $this->assertArrayNotHasKey($webLoginKey, $payload);
        $this->assertArrayNotHasKey('auth_generation_web', $payload);
        $this->assertArrayNotHasKey('password_hash_web', $payload);
        $this->assertArrayNotHasKey(InitializeTenancy::SESSION_SHOP_KEY, $payload);
        $this->assertSame('platform-admin-id', $payload[$platformLoginKey]);
        $this->assertSame('platform-generation', $payload['auth_generation_platform']);
        $this->assertSame('platform-password-hash', $payload['password_hash_platform']);
        $this->assertSame([
            'audit_id' => 'support-audit-id',
            'shop_id' => $shop->getKey(),
        ], $payload[SupportAccessManager::SESSION_KEY]);
        $this->assertSame(['sale_id' => 91], $payload['tenant.checkout.draft']);
        $this->assertNull(DB::connection('central')->table('sessions')->where('id', $matchingSessionId)->value('user_id'));
        $this->assertSame(
            $this->encodeSessionPayload($differentShopPayload),
            DB::connection('central')->table('sessions')->where('id', 'different-shop-session')->value('payload'),
        );
        $this->assertSame(
            $this->encodeSessionPayload($differentUserPayload),
            DB::connection('central')->table('sessions')->where('id', 'different-user-session')->value('payload'),
        );
        $this->assertSame(
            $this->encodeSessionPayload($malformedUserPayload),
            DB::connection('central')->table('sessions')->where('id', 'malformed-user-session')->value('payload'),
        );
        $this->assertSame(
            'not-base64',
            DB::connection('central')->table('sessions')->where('id', 'malformed-session')->value('payload'),
        );
    }

    public function test_stale_session_write_after_deactivation_cannot_authenticate_after_reactivation(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-stale-session-write');
        $actorId = $this->manager->within(
            $shop,
            static fn (): int => (int) User::factory()->admin()->create()->getKey(),
        );
        $loginResponse = $this->post($this->tenantUrl($shop, '/login'), [
            'username' => $user->username,
            'password' => 'secret-password',
        ])->assertRedirect();
        $sessionId = $loginResponse->baseRequest->session()->getId();
        $stalePayload = DB::connection('central')
            ->table('sessions')
            ->where('id', $sessionId)
            ->value('payload');
        $this->assertIsString($stalePayload);
        $this->resetResolvedSessionAndGuards();

        $this->manager->within($shop, static function () use ($actorId, $user): void {
            resolve(ManageTenantUsers::class)->update(
                User::query()->findOrFail($actorId),
                User::query()->findOrFail($user->getKey()),
                ['is_active' => false],
                Role::Manager->value,
            );
        });

        DB::connection('central')->table('sessions')->where('id', $sessionId)->update([
            'payload' => $stalePayload,
            'user_id' => $user->getKey(),
        ]);

        $this->manager->within($shop, static function () use ($actorId, $user): void {
            resolve(ManageTenantUsers::class)->update(
                User::query()->findOrFail($actorId),
                User::query()->findOrFail($user->getKey()),
                ['is_active' => true],
                Role::Manager->value,
            );
        });
        $this->resetResolvedSessionAndGuards();

        $response = $this->withCookie((string) config('session.cookie'), $sessionId)
            ->get($this->tenantUrl($shop, '/quick-items'));

        $response
            ->assertRedirect($this->tenantUrl($shop, '/login'))
            ->assertSessionMissing(Auth::guard('web')->getName())
            ->assertSessionMissing(TenantSessionAuthentication::GENERATION_SESSION_KEY);
        $this->assertTenantStateIsRevoked();
    }

    public function test_deleting_a_tenant_user_rotates_credentials_and_revokes_matching_sessions(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-deleted-user');
        $actorId = $this->manager->within(
            $shop,
            static fn (): int => (int) User::factory()->admin()->create()->getKey(),
        );
        $oldRememberToken = $user->getRememberToken();
        $tokenAtDelete = null;
        Event::listen(
            'eloquent.deleting: '.User::class,
            static function (User $deletingUser) use ($user, &$tokenAtDelete): void {
                if ((string) $deletingUser->getKey() === (string) $user->getKey()) {
                    $tokenAtDelete = $deletingUser->getRememberToken();
                }
            },
        );
        $webLoginKey = Auth::guard('web')->getName();
        $platformLoginKey = Auth::guard('platform')->getName();
        $sessionId = 'deleted-tenant-user-session';
        DB::connection('central')->table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => $this->encodeSessionPayload([
                '_token' => 'csrf-token',
                $webLoginKey => $user->getKey(),
                TenantSessionAuthentication::GENERATION_SESSION_KEY => 'old-generation',
                'password_hash_web' => 'password-hash',
                InitializeTenancy::SESSION_SHOP_KEY => $shop->getKey(),
                $platformLoginKey => 'platform-admin-id',
                'tenant.checkout.draft' => ['sale_id' => 91],
            ]),
            'last_activity' => now()->timestamp,
        ]);

        $this->manager->within($shop, static function () use ($actorId, $user): void {
            resolve(ManageTenantUsers::class)->delete(
                User::query()->findOrFail($actorId),
                User::query()->findOrFail($user->getKey()),
            );
        });

        $this->assertIsString($tokenAtDelete);
        $this->assertNotSame($oldRememberToken, $tokenAtDelete);
        $payload = $this->persistedSessionPayload($sessionId);
        $this->assertArrayNotHasKey($webLoginKey, $payload);
        $this->assertArrayNotHasKey(TenantSessionAuthentication::GENERATION_SESSION_KEY, $payload);
        $this->assertArrayNotHasKey('password_hash_web', $payload);
        $this->assertArrayNotHasKey(InitializeTenancy::SESSION_SHOP_KEY, $payload);
        $this->assertSame('platform-admin-id', $payload[$platformLoginKey]);
        $this->assertSame(['sale_id' => 91], $payload['tenant.checkout.draft']);
        $this->assertNull(DB::connection('central')->table('sessions')->where('id', $sessionId)->value('user_id'));
        $this->manager->within(
            $shop,
            fn (): mixed => $this->assertDatabaseMissing('users', ['id' => $user->getKey()], 'tenant'),
        );
    }

    public function test_repeated_deactivation_repairs_orphaned_tenant_authentication_idempotently(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-repeated-deactivation');
        $actorId = $this->manager->within(
            $shop,
            static fn (): int => (int) User::factory()->admin()->create()->getKey(),
        );
        $this->manager->within($shop, static function () use ($user): void {
            User::query()->whereKey($user->getKey())->update(['is_active' => false]);
        });
        $webLoginKey = Auth::guard('web')->getName();
        $platformLoginKey = Auth::guard('platform')->getName();
        $sessionId = 'already-inactive-tenant-user-session';
        DB::connection('central')->table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => $this->encodeSessionPayload([
                '_token' => 'csrf-token',
                $webLoginKey => $user->getKey(),
                TenantSessionAuthentication::GENERATION_SESSION_KEY => 'old-generation',
                InitializeTenancy::SESSION_SHOP_KEY => $shop->getKey(),
                $platformLoginKey => 'platform-admin-id',
            ]),
            'last_activity' => now()->timestamp,
        ]);

        $deactivate = function () use ($shop, $actorId, $user): void {
            $this->manager->within($shop, static function () use ($actorId, $user): void {
                resolve(ManageTenantUsers::class)->update(
                    User::query()->findOrFail($actorId),
                    User::query()->findOrFail($user->getKey()),
                    ['is_active' => false],
                    Role::Manager->value,
                );
            });
        };

        $deactivate();
        $payloadAfterFirstDeactivation = $this->persistedSessionPayload($sessionId);
        $this->assertArrayNotHasKey($webLoginKey, $payloadAfterFirstDeactivation);
        $this->assertArrayNotHasKey(TenantSessionAuthentication::GENERATION_SESSION_KEY, $payloadAfterFirstDeactivation);
        $this->assertArrayNotHasKey(InitializeTenancy::SESSION_SHOP_KEY, $payloadAfterFirstDeactivation);
        $this->assertSame('platform-admin-id', $payloadAfterFirstDeactivation[$platformLoginKey]);

        $deactivate();

        $this->assertSame($payloadAfterFirstDeactivation, $this->persistedSessionPayload($sessionId));
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

    public function test_suspended_tenant_invalidates_database_session_without_losing_platform_identity(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-suspended');
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
                'remember' => true,
            ]);

        $loginResponse
            ->assertRedirect()
            ->assertSessionHas($platformSessionKey, $platformUser->getKey());
        $tenantSessionId = $loginResponse->baseRequest->session()->getId();
        $recallerName = Auth::guard('web')->getRecallerName();
        $tenantBindingName = $recallerName.'_tenant';
        $recaller = $loginResponse->getCookie($recallerName);
        $tenantBinding = $loginResponse->getCookie($tenantBindingName);
        $this->assertNotNull($recaller);
        $this->assertNotNull($tenantBinding);
        $this->assertTenantStateIsRevoked();
        $this->resetResolvedSessionAndGuards();
        $shop->suspend();

        $unavailableResponse = $this->withCookies([
            (string) config('session.cookie') => $tenantSessionId,
            $recallerName => $recaller->getValue(),
            $tenantBindingName => $tenantBinding->getValue(),
        ])
            ->get($this->tenantUrl($shop, '/quick-items'));

        $unavailableResponse
            ->assertStatus(503)
            ->assertSee('Shop unavailable')
            ->assertSessionHas($platformSessionKey, $platformUser->getKey())
            ->assertSessionMissing(Auth::guard('web')->getName())
            ->assertSessionMissing(InitializeTenancy::SESSION_SHOP_KEY)
            ->assertCookieExpired($recallerName)
            ->assertCookieExpired($tenantBindingName);
        $invalidatedSessionId = $unavailableResponse->baseRequest->session()->getId();
        $this->assertNotSame($tenantSessionId, $invalidatedSessionId);
        $this->assertDatabaseMissing('sessions', ['id' => $tenantSessionId], 'central');
        $this->assertDatabaseHas('sessions', ['id' => $invalidatedSessionId], 'central');
        $this->assertTenantStateIsRevoked();
        $this->resetResolvedSessionAndGuards();
        unset($this->defaultCookies[$recallerName], $this->defaultCookies[$tenantBindingName]);

        $this->withCookie((string) config('session.cookie'), $invalidatedSessionId)
            ->get('/platform')
            ->assertOk();
        $this->assertAuthenticatedAs($platformUser, 'platform');
        $this->assertGuest('web');
        $this->assertTenantStateIsRevoked();
    }

    public function test_attestation_failure_invalidates_authenticated_database_session_before_the_error_response(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('database-attestation-failure');
        $loginResponse = $this->post($this->tenantUrl($shop, '/login'), [
            'username' => $user->username,
            'password' => 'secret-password',
        ])->assertRedirect();
        $tenantSessionId = $loginResponse->baseRequest->session()->getId();
        $this->assertTenantStateIsRevoked();
        $this->resetResolvedSessionAndGuards();
        $this->manager->within($shop, static function (): void {
            DB::connection('tenant')->table('tenant_installations')->update([
                'attestation_hmac' => str_repeat('0', 64),
            ]);
        });

        $unavailableResponse = $this->withCookie((string) config('session.cookie'), $tenantSessionId)
            ->get($this->tenantUrl($shop, '/quick-items'));

        $unavailableResponse
            ->assertStatus(503)
            ->assertSee('Shop unavailable')
            ->assertSessionMissing(Auth::guard('web')->getName())
            ->assertSessionMissing(InitializeTenancy::SESSION_SHOP_KEY);
        $invalidatedSessionId = $unavailableResponse->baseRequest->session()->getId();
        $this->assertNotSame($tenantSessionId, $invalidatedSessionId);
        $this->assertDatabaseMissing('sessions', ['id' => $tenantSessionId], 'central');
        $this->assertDatabaseHas('sessions', ['id' => $invalidatedSessionId], 'central');
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
                'remember' => true,
            ]);

        $loginResponse
            ->assertRedirect()
            ->assertSessionHas($platformSessionKey, $platformUser->getKey());
        $tenantSessionId = $loginResponse->baseRequest->session()->getId();
        $recallerName = Auth::guard('web')->getRecallerName();
        $tenantBindingName = $recallerName.'_tenant';
        $recaller = $loginResponse->getCookie($recallerName);
        $tenantBinding = $loginResponse->getCookie($tenantBindingName);
        $this->assertNotNull($recaller);
        $this->assertNotNull($tenantBinding);
        $this->assertNotSame($platformSessionId, $tenantSessionId);
        $this->assertTenantStateIsRevoked();
        $this->resetResolvedSessionAndGuards();

        $logoutResponse = $this->withCookies([
            (string) config('session.cookie') => $tenantSessionId,
            $recallerName => $recaller->getValue(),
            $tenantBindingName => $tenantBinding->getValue(),
        ])
            ->post($this->tenantUrl($shop, '/logout'));

        $logoutResponse
            ->assertRedirect($this->tenantUrl($shop, '/login'))
            ->assertSessionHas($platformSessionKey, $platformUser->getKey())
            ->assertSessionMissing(Auth::guard('web')->getName())
            ->assertSessionMissing(InitializeTenancy::SESSION_SHOP_KEY)
            ->assertCookieExpired($recallerName)
            ->assertCookieExpired($tenantBindingName);
        $loggedOutSessionId = $logoutResponse->baseRequest->session()->getId();
        $this->assertNotSame($tenantSessionId, $loggedOutSessionId);
        $this->assertDatabaseMissing('sessions', ['id' => $tenantSessionId], 'central');
        $this->assertDatabaseHas('sessions', ['id' => $loggedOutSessionId], 'central');
        $this->assertTenantStateIsRevoked();
        $this->resetResolvedSessionAndGuards();
        unset($this->defaultCookies[$recallerName], $this->defaultCookies[$tenantBindingName]);

        $this->withCookie((string) config('session.cookie'), $loggedOutSessionId)
            ->get('/platform')
            ->assertOk();
        $this->assertAuthenticatedAs($platformUser, 'platform');
        $this->assertGuest('web');
        $this->assertTenantStateIsRevoked();
    }

    public function test_platform_logout_preserves_a_coexisting_tenant_database_session(): void
    {
        [$shop, $user] = $this->createActiveTenantWithManager('platform-database-logout');
        $platformUser = PlatformUser::factory()->create();
        $platformGuard = Auth::guard('platform');
        $platformGuard->login($platformUser);
        session()->save();
        $platformSessionId = session()->getId();
        $this->resetResolvedSessionAndGuards();

        $loginResponse = $this->withCookie((string) config('session.cookie'), $platformSessionId)
            ->post($this->tenantUrl($shop, '/login'), [
                'username' => $user->username,
                'password' => 'secret-password',
            ]);

        $loginResponse->assertRedirect();
        $tenantSessionId = $loginResponse->baseRequest->session()->getId();
        $webSessionKey = Auth::guard('web')->getName();
        $platformSessionKey = Auth::guard('platform')->getName();
        $loginResponse
            ->assertSessionHas($webSessionKey, $user->getKey())
            ->assertSessionHas($platformSessionKey, $platformUser->getKey());
        $this->resetResolvedSessionAndGuards();

        $logoutResponse = $this->withCookie((string) config('session.cookie'), $tenantSessionId)
            ->post('https://pos.example.test/platform/logout');

        $logoutResponse
            ->assertRedirect()
            ->assertSessionHas($webSessionKey, $user->getKey())
            ->assertSessionHas(InitializeTenancy::SESSION_SHOP_KEY, $shop->getKey())
            ->assertSessionMissing($platformSessionKey);
        $loggedOutSessionId = $logoutResponse->baseRequest->session()->getId();
        $this->assertNotSame($tenantSessionId, $loggedOutSessionId);
        $this->assertDatabaseMissing('sessions', ['id' => $tenantSessionId], 'central');
        $this->assertDatabaseHas('sessions', ['id' => $loggedOutSessionId], 'central');
        $encodedPayload = DB::connection('central')
            ->table('sessions')
            ->where('id', $loggedOutSessionId)
            ->value('payload');
        $this->assertIsString($encodedPayload);
        $payload = json_decode(base64_decode($encodedPayload, true), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($user->getKey(), $payload[$webSessionKey]);
        $this->assertArrayNotHasKey($platformSessionKey, $payload);
        $this->assertTenantStateIsRevoked();
        $this->resetResolvedSessionAndGuards();

        $this->withCredentials()
            ->withCookie((string) config('session.cookie'), $loggedOutSessionId)
            ->getJson($this->tenantUrl($shop, '/quick-items'))
            ->assertOk();
        $this->assertGuest('platform');
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

    /** @param array<string, mixed> $payload */
    private function encodeSessionPayload(array $payload): string
    {
        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function persistedSessionPayload(string $sessionId): array
    {
        $encodedPayload = DB::connection('central')
            ->table('sessions')
            ->where('id', $sessionId)
            ->value('payload');

        $this->assertIsString($encodedPayload);

        return json_decode(base64_decode($encodedPayload, true), true, flags: JSON_THROW_ON_ERROR);
    }

    private function resetResolvedSessionAndGuards(): void
    {
        Auth::forgetGuards();
        config()->set('auth.defaults.guard', 'web');
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
