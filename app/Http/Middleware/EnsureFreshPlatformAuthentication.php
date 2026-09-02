<?php

namespace App\Http\Middleware;

use App\Models\Central\PlatformUser;
use App\Support\PlatformSessionAuthentication;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureFreshPlatformAuthentication
{
    public function __construct(
        private readonly PlatformSessionAuthentication $sessionAuthentication,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('platform');
        $authenticatedUser = $guard->user();

        if (! $authenticatedUser instanceof PlatformUser) {
            return redirect()->guest(Filament::getLoginUrl());
        }

        $platformUser = PlatformUser::query()
            ->whereKey($authenticatedUser->getKey())
            ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->first();

        if (! $platformUser instanceof PlatformUser
            || ! $this->sessionAuthentication->currentSessionMatches($platformUser)) {
            $this->sessionAuthentication->forgetCurrentAuthentication($authenticatedUser->getKey());

            return redirect()->guest(Filament::getLoginUrl());
        }

        $guard->setUser($platformUser);

        return $next($request);
    }
}
