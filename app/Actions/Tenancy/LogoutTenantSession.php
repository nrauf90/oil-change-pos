<?php

namespace App\Actions\Tenancy;

use App\Http\Middleware\InitializeTenancy;
use App\Support\TenantSessionAuthentication;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;

final readonly class LogoutTenantSession
{
    public function __construct(
        private AuthManager $auth,
        private TenantSessionAuthentication $sessionAuthentication,
    ) {}

    public function handle(Request $request): void
    {
        $this->auth->guard('web')->logout();
        $this->sessionAuthentication->forgetTenantRecaller($request);

        $request->session()->forget(InitializeTenancy::SESSION_SHOP_KEY);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
    }
}
