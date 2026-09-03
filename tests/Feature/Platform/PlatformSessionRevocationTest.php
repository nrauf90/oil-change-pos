<?php

namespace Tests\Feature\Platform;

use App\Actions\ManagePlatformUsers;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopAccessSession;
use App\Tenancy\SupportAccessManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class PlatformSessionRevocationTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        Auth::forgetGuards();
    }

    public function test_each_platform_request_reloads_the_authenticated_user_from_central(): void
    {
        $platformUser = PlatformUser::factory()->create();
        PlatformUser::factory()->create();
        Auth::guard('platform')->login($platformUser);

        DB::connection('central')
            ->table('platform_users')
            ->where('id', $platformUser->getKey())
            ->update(['is_active' => false]);

        $this->get('/platform')
            ->assertRedirect('/platform/login');

        $this->assertGuest('platform');
    }

    public function test_deactivation_clears_current_platform_authentication_but_preserves_web_state(): void
    {
        $platformUser = PlatformUser::factory()->create();
        PlatformUser::factory()->create();
        $platformGuard = Auth::guard('platform');
        $webGuard = Auth::guard('web');

        $platformGuard->login($platformUser, remember: true);
        session()->put($webGuard->getName(), 731);
        session()->put('tenant.checkout.draft', ['sale_id' => 91]);

        $oldRememberToken = $platformUser->fresh()->getRememberToken();
        $platformUser->deactivate();

        $this->assertGuest('platform');
        $this->assertFalse(session()->has($platformGuard->getName()));
        $this->assertSame(731, session()->get($webGuard->getName()));
        $this->assertSame(['sale_id' => 91], session()->get('tenant.checkout.draft'));
        $this->assertNotSame($oldRememberToken, $platformUser->fresh()->getRememberToken());
    }

    public function test_deactivation_removes_only_platform_authentication_from_persisted_central_sessions(): void
    {
        $platformUser = PlatformUser::factory()->create();
        PlatformUser::factory()->create();
        $platformGuard = Auth::guard('platform');
        $webGuard = Auth::guard('web');
        $sessionId = 'coexisting-platform-and-tenant-session';

        DB::connection('central')->table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => 731,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => $this->encodeSessionPayload([
                '_token' => 'csrf-token',
                $platformGuard->getName() => $platformUser->getKey(),
                'password_hash_platform' => $platformUser->getAuthPassword(),
                $webGuard->getName() => 731,
                'tenant.checkout.draft' => ['sale_id' => 91],
            ]),
            'last_activity' => now()->timestamp,
        ]);

        $platformUser->deactivate();

        $payload = $this->persistedSessionPayload($sessionId);

        $this->assertArrayNotHasKey($platformGuard->getName(), $payload);
        $this->assertArrayNotHasKey('password_hash_platform', $payload);
        $this->assertSame(731, $payload[$webGuard->getName()]);
        $this->assertSame(['sale_id' => 91], $payload['tenant.checkout.draft']);
        $this->assertSame('csrf-token', $payload['_token']);
    }

    public function test_deactivation_ends_active_support_audits_and_removes_the_support_tuple(): void
    {
        $platformUser = PlatformUser::factory()->create();
        PlatformUser::factory()->create();
        $firstShop = Shop::factory()->create();
        $secondShop = Shop::factory()->create();
        $endedShop = Shop::factory()->create();
        $this->travelTo('2026-09-03 09:00:00');
        $alreadyEndedAudit = ShopAccessSession::start($platformUser, $endedShop);
        $alreadyEndedAudit->end();
        $this->travelTo('2026-09-03 10:00:00');
        $firstActiveAudit = ShopAccessSession::start($platformUser, $firstShop);
        $secondActiveAudit = ShopAccessSession::start($platformUser, $secondShop);
        $platformGuard = Auth::guard('platform');
        $webGuard = Auth::guard('web');
        $sessionId = 'deactivated-platform-support-session';

        DB::connection('central')->table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => 731,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => $this->encodeSessionPayload([
                '_token' => 'csrf-token',
                $platformGuard->getName() => $platformUser->getKey(),
                'auth_generation_platform' => 'stale-generation',
                SupportAccessManager::SESSION_KEY => [
                    'audit_id' => $firstActiveAudit->getKey(),
                    'shop_id' => $firstShop->getKey(),
                ],
                $webGuard->getName() => 731,
                'tenant.checkout.draft' => ['sale_id' => 91],
            ]),
            'last_activity' => now()->timestamp,
        ]);
        $this->travelTo('2026-09-03 10:30:00');

        $platformUser->deactivate();

        $this->assertSame('2026-09-03 10:30:00', $firstActiveAudit->fresh()->ended_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-03 10:30:00', $secondActiveAudit->fresh()->ended_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-03 09:00:00', $alreadyEndedAudit->fresh()->ended_at?->format('Y-m-d H:i:s'));

        $payload = $this->persistedSessionPayload($sessionId);

        $this->assertArrayNotHasKey($platformGuard->getName(), $payload);
        $this->assertArrayNotHasKey('auth_generation_platform', $payload);
        $this->assertArrayNotHasKey(SupportAccessManager::SESSION_KEY, $payload);
        $this->assertSame(731, $payload[$webGuard->getName()]);
        $this->assertSame(['sale_id' => 91], $payload['tenant.checkout.draft']);
    }

    public function test_deactivation_does_not_revoke_authentication_when_audit_teardown_fails(): void
    {
        $platformUser = PlatformUser::factory()->create();
        PlatformUser::factory()->create();
        $platformGuard = Auth::guard('platform');
        $platformGuard->login($platformUser, remember: true);
        $oldRememberToken = $platformUser->fresh()->getRememberToken();
        Schema::connection('central')->drop('shop_access_sessions');
        $caughtException = null;

        try {
            $platformUser->deactivate();
        } catch (QueryException $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(QueryException::class, $caughtException);
        $this->assertTrue($platformUser->fresh()->is_active);
        $this->assertSame($oldRememberToken, $platformUser->fresh()->getRememberToken());
        $this->assertAuthenticatedAs($platformUser, 'platform');
        $this->assertTrue(session()->has($platformGuard->getName()));
    }

    public function test_repeated_deactivation_cleans_orphan_access_from_an_already_inactive_user(): void
    {
        $platformUser = PlatformUser::factory()->create();
        PlatformUser::factory()->create();
        $shop = Shop::factory()->create();
        $platformGuard = Auth::guard('platform');
        $webGuard = Auth::guard('web');
        $platformGuard->login($platformUser, remember: true);
        session()->save();
        $sessionId = session()->getId();
        $audit = ShopAccessSession::start($platformUser, $shop);
        session()->put([
            SupportAccessManager::SESSION_KEY => [
                'audit_id' => $audit->getKey(),
                'shop_id' => $shop->getKey(),
            ],
            $webGuard->getName() => 731,
            'tenant.checkout.draft' => ['sale_id' => 91],
        ]);
        DB::connection('central')->table('sessions')->where('id', $sessionId)->update([
            'payload' => $this->encodeSessionPayload(session()->all()),
            'user_id' => 731,
        ]);
        $oldRememberToken = $platformUser->fresh()->getRememberToken();
        DB::connection('central')
            ->table('platform_users')
            ->where('id', $platformUser->getKey())
            ->update(['is_active' => false]);
        $platformUser->refresh();
        $this->travelTo('2026-09-03 11:00:00');

        $platformUser->deactivate();

        $this->assertSame('2026-09-03 11:00:00', $audit->fresh()->ended_at?->format('Y-m-d H:i:s'));
        $this->assertSame($oldRememberToken, $platformUser->fresh()->getRememberToken());
        $this->assertGuest('platform');
        $this->assertFalse(session()->has($platformGuard->getName()));
        $this->assertFalse(session()->has(SupportAccessManager::SESSION_KEY));
        $this->assertSame(731, session()->get($webGuard->getName()));
        $this->assertSame(['sale_id' => 91], session()->get('tenant.checkout.draft'));
        $payload = $this->persistedSessionPayload($sessionId);
        $this->assertArrayNotHasKey($platformGuard->getName(), $payload);
        $this->assertArrayNotHasKey('auth_generation_platform', $payload);
        $this->assertArrayNotHasKey('password_hash_platform', $payload);
        $this->assertArrayNotHasKey(SupportAccessManager::SESSION_KEY, $payload);
        $this->assertSame(731, $payload[$webGuard->getName()]);
        $this->assertSame(['sale_id' => 91], data_get($payload, 'tenant.checkout.draft'));
        $endedAt = $audit->fresh()->ended_at;
        $payloadAfterFirstDeactivation = $payload;
        $this->travelTo('2026-09-03 11:30:00');

        $platformUser->deactivate();

        $this->assertTrue($audit->fresh()->ended_at?->equalTo($endedAt));
        $this->assertSame($payloadAfterFirstDeactivation, $this->persistedSessionPayload($sessionId));
        $this->assertGuest('platform');
        $this->assertSame(731, session()->get($webGuard->getName()));
        $this->assertSame(['sale_id' => 91], session()->get('tenant.checkout.draft'));
    }

    public function test_old_platform_session_id_cannot_authenticate_after_reactivation(): void
    {
        $platformUser = PlatformUser::factory()->create();
        PlatformUser::factory()->create();
        $platformGuard = Auth::guard('platform');
        $webGuard = Auth::guard('web');
        $sessionId = str_repeat('a', 40);

        DB::connection('central')->table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => 731,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => $this->encodeSessionPayload([
                '_token' => 'csrf-token',
                $platformGuard->getName() => $platformUser->getKey(),
                $webGuard->getName() => 731,
                'tenant.checkout.draft' => ['sale_id' => 91],
            ]),
            'last_activity' => now()->timestamp,
        ]);

        $platformUser->deactivate();
        $platformUser->activate();
        Auth::forgetGuards();

        $response = $this->withCookie((string) config('session.cookie'), $sessionId)
            ->get('/platform');

        $response->assertRedirect('/platform/login');
        $this->assertSame($sessionId, $response->baseRequest->session()->getId());
        $this->assertArrayNotHasKey(
            $platformGuard->getName(),
            $response->baseRequest->session()->all(),
        );

        $payload = $this->persistedSessionPayload($sessionId);

        $this->assertArrayNotHasKey($platformGuard->getName(), $payload);
        $this->assertSame(731, $payload[$webGuard->getName()]);
        $this->assertSame(['sale_id' => 91], $payload['tenant.checkout.draft']);
    }

    public function test_stale_session_write_after_deactivation_cannot_restore_platform_authentication(): void
    {
        $platformUser = PlatformUser::factory()->create();
        PlatformUser::factory()->create();
        $platformGuard = Auth::guard('platform');
        $webGuard = Auth::guard('web');
        $oldRememberToken = $platformUser->getRememberToken();
        $sessionId = str_repeat('b', 40);

        $platformUser->deactivate();

        DB::connection('central')->table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => 731,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => $this->encodeSessionPayload([
                '_token' => 'csrf-token',
                $platformGuard->getName() => $platformUser->getKey(),
                'auth_generation_platform' => hash_hmac(
                    'sha256',
                    (string) $oldRememberToken,
                    (string) config('app.key'),
                ),
                $webGuard->getName() => 731,
                'tenant.checkout.draft' => ['sale_id' => 91],
            ]),
            'last_activity' => now()->timestamp,
        ]);

        $platformUser->activate();
        Auth::forgetGuards();

        $response = $this->withCookie((string) config('session.cookie'), $sessionId)
            ->get('/platform');

        $response->assertRedirect('/platform/login');
        $this->assertSame($sessionId, $response->baseRequest->session()->getId());
        $this->assertArrayNotHasKey(
            $platformGuard->getName(),
            $response->baseRequest->session()->all(),
        );

        $payload = $this->persistedSessionPayload($sessionId);

        $this->assertArrayNotHasKey($platformGuard->getName(), $payload);
        $this->assertArrayNotHasKey('auth_generation_platform', $payload);
        $this->assertSame(731, $payload[$webGuard->getName()]);
        $this->assertSame(['sale_id' => 91], $payload['tenant.checkout.draft']);
    }

    public function test_old_remember_cookie_cannot_authenticate_after_reactivation(): void
    {
        $platformUser = PlatformUser::factory()->create();
        PlatformUser::factory()->create();
        $platformGuard = Auth::guard('platform');
        $oldRememberToken = $platformUser->getRememberToken();
        $oldRememberCookie = implode('|', [
            $platformUser->getAuthIdentifier(),
            $oldRememberToken,
            $platformGuard->hashPasswordForCookie($platformUser->getAuthPassword()),
        ]);

        $platformUser->deactivate();
        $platformUser->activate();
        Auth::forgetGuards();

        $this->withCookie($platformGuard->getRecallerName(), $oldRememberCookie)
            ->get('/platform')
            ->assertRedirect('/platform/login');

        $this->assertGuest('platform');
        $this->assertNotSame($oldRememberToken, $platformUser->fresh()->getRememberToken());
    }

    public function test_managed_reactivation_of_a_legacy_inactive_user_revokes_old_authentication(): void
    {
        $actor = PlatformUser::factory()->create();
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();
        $orphanedAudit = ShopAccessSession::start($platformUser, $shop);
        $platformGuard = Auth::guard('platform');
        $webGuard = Auth::guard('web');
        $oldRememberToken = $platformUser->getRememberToken();
        $oldRememberCookie = implode('|', [
            $platformUser->getAuthIdentifier(),
            $oldRememberToken,
            $platformGuard->hashPasswordForCookie($platformUser->getAuthPassword()),
        ]);
        $sessionId = 'legacy-inactive-platform-session';
        DB::connection('central')->table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => 731,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => $this->encodeSessionPayload([
                '_token' => 'csrf-token',
                $platformGuard->getName() => $platformUser->getKey(),
                'auth_generation_platform' => 'legacy-generation',
                SupportAccessManager::SESSION_KEY => [
                    'audit_id' => $orphanedAudit->getKey(),
                    'shop_id' => $shop->getKey(),
                ],
                $webGuard->getName() => 731,
                'tenant.checkout.draft' => ['sale_id' => 91],
            ]),
            'last_activity' => now()->timestamp,
        ]);
        DB::connection('central')
            ->table('platform_users')
            ->where('id', $platformUser->getKey())
            ->update(['is_active' => false]);
        $platformUser->refresh();

        resolve(ManagePlatformUsers::class)->update(
            $actor,
            $platformUser,
            ['is_active' => true],
        );

        $this->assertTrue($platformUser->fresh()->is_active);
        $this->assertNotSame($oldRememberToken, $platformUser->fresh()->getRememberToken());
        $this->assertNotNull($orphanedAudit->fresh()->ended_at);
        $payload = $this->persistedSessionPayload($sessionId);
        $this->assertArrayNotHasKey($platformGuard->getName(), $payload);
        $this->assertArrayNotHasKey('auth_generation_platform', $payload);
        $this->assertArrayNotHasKey(SupportAccessManager::SESSION_KEY, $payload);
        $this->assertSame(731, $payload[$webGuard->getName()]);
        $this->assertSame(['sale_id' => 91], $payload['tenant.checkout.draft']);
        Auth::forgetGuards();

        $this->withCookie($platformGuard->getRecallerName(), $oldRememberCookie)
            ->get('/platform')
            ->assertRedirect('/platform/login');

        $this->assertGuest('platform');
    }

    public function test_password_change_revokes_active_platform_and_support_sessions(): void
    {
        $actor = PlatformUser::factory()->create();
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();
        $activeAudit = ShopAccessSession::start($platformUser, $shop);
        $platformGuard = Auth::guard('platform');
        $webGuard = Auth::guard('web');
        $oldRememberToken = $platformUser->getRememberToken();
        $sessionId = 'platform-password-change-session';
        DB::connection('central')->table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => 731,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => $this->encodeSessionPayload([
                '_token' => 'csrf-token',
                $platformGuard->getName() => $platformUser->getKey(),
                'auth_generation_platform' => hash_hmac(
                    'sha256',
                    (string) $oldRememberToken,
                    (string) config('app.key'),
                ),
                SupportAccessManager::SESSION_KEY => [
                    'audit_id' => $activeAudit->getKey(),
                    'shop_id' => $shop->getKey(),
                ],
                $webGuard->getName() => 731,
                'tenant.checkout.draft' => ['sale_id' => 91],
            ]),
            'last_activity' => now()->timestamp,
        ]);

        resolve(ManagePlatformUsers::class)->update(
            $actor,
            $platformUser,
            ['password' => 'replacement-platform-password'],
        );

        $freshPlatformUser = $platformUser->fresh();
        $this->assertTrue(Hash::check('replacement-platform-password', $freshPlatformUser->password));
        $this->assertNotSame($oldRememberToken, $freshPlatformUser->getRememberToken());
        $this->assertNotNull($activeAudit->fresh()->ended_at);
        $payload = $this->persistedSessionPayload($sessionId);
        $this->assertArrayNotHasKey($platformGuard->getName(), $payload);
        $this->assertArrayNotHasKey('auth_generation_platform', $payload);
        $this->assertArrayNotHasKey(SupportAccessManager::SESSION_KEY, $payload);
        $this->assertSame(731, $payload[$webGuard->getName()]);
        $this->assertSame(['sale_id' => 91], $payload['tenant.checkout.draft']);
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
}
