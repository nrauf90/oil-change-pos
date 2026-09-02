<?php

namespace App\Http\Middleware;

use App\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks routes belonging to a module disabled by either the platform ceiling
 * or the tenant's own module preference.
 *
 * An unknown module key is treated as disabled, so a typo in a route
 * definition fails closed rather than silently exposing a feature.
 */
class EnsureModuleIsEnabled
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->modules->enabled($module), 404);

        return $next($request);
    }
}
