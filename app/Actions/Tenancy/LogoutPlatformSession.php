<?php

namespace App\Actions\Tenancy;

use App\Models\Central\PlatformUser;
use App\Models\Central\ShopAccessSession;
use App\Tenancy\SupportAccessManager;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;

final readonly class LogoutPlatformSession
{
    public function __construct(private AuthManager $auth) {}

    public function handle(Request $request): void
    {
        $guard = $this->auth->guard('platform');
        $platformUser = $guard->user();

        if (! $platformUser instanceof PlatformUser || $platformUser::class !== PlatformUser::class) {
            throw new AuthenticationException;
        }

        ShopAccessSession::endActiveForPlatformUser($platformUser);

        $guard->logout();
        $request->session()->forget([
            SupportAccessManager::SESSION_KEY,
            'auth_generation_platform',
            'password_hash_platform',
        ]);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
    }
}
