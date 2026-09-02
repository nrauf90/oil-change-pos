<?php

namespace App\Tenancy;

use App\Http\Middleware\InitializeTenancy;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Http\Request;
use RuntimeException;

final readonly class TenantSessionInvalidator
{
    public function __construct(
        private AuthManager $auth,
        private CookieJar $cookies,
    ) {}

    public function invalidate(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $guard = $this->auth->guard('web');

        if (! $guard instanceof SessionGuard) {
            throw new RuntimeException('The tenant web guard must use session authentication.');
        }

        $session = $request->session();
        $recallerName = $guard->getRecallerName();
        $session->forget([
            $guard->getName(),
            'password_hash_web',
            InitializeTenancy::SESSION_SHOP_KEY,
        ]);
        $request->cookies->remove($recallerName);
        $this->cookies->queue($this->cookies->forget($recallerName));
        $guard->forgetUser();
        $session->regenerate(true);
    }
}
