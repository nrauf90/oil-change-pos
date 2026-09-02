<?php

namespace Tests\Feature\Platform;

use App\Models\Central\PlatformUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
