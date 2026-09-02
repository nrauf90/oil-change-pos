<?php

namespace App\Http\Middleware;

use App\Tenancy\SupportAccessContext;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnforceReadOnlySupportAccess
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private SupportAccessContext $context,
        private AuthManager $auth,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->active()) {
            return $next($request);
        }

        if (! in_array($request->getMethod(), self::SAFE_METHODS, true)
            && ! $request->routeIs('support-access.exit')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if ($request->routeIs('support-access.exit')) {
            return $next($request);
        }

        $guard = $this->auth->guard('web');

        if (! $guard instanceof SessionGuard) {
            throw new RuntimeException('The tenant web guard must use session authentication.');
        }

        $previousDefaultGuard = $this->auth->getDefaultDriver();
        $guard->setUser($this->context->principal());
        $this->auth->shouldUse('web');

        try {
            return $next($request);
        } finally {
            $guard->forgetUser();
            $this->auth->shouldUse($previousDefaultGuard);
        }
    }
}
