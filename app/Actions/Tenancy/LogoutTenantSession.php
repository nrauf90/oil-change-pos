<?php

namespace App\Actions\Tenancy;

use App\Http\Middleware\InitializeTenancy;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;

final readonly class LogoutTenantSession
{
    public function __construct(private AuthManager $auth) {}

    public function handle(Request $request): void
    {
        $this->auth->guard('web')->logout();

        $request->session()->forget(InitializeTenancy::SESSION_SHOP_KEY);
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
    }
}
