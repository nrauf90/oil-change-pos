<?php

use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Http\Middleware\EnsureShopIsActive;
use App\Http\Middleware\InitializeTenancy;
use App\Http\Middleware\TenantThrottleRequests;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts();
        $middleware->append(InitializeTenancy::class);
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'module' => EnsureModuleIsEnabled::class,
            'shop.active' => EnsureShopIsActive::class,
            'tenant' => InitializeTenancy::class,
            'throttle' => TenantThrottleRequests::class,
        ]);
        $middleware->appendToPriorityList(StartSession::class, EnsureShopIsActive::class);
        $middleware->appendToPriorityList(EnsureShopIsActive::class, InitializeTenancy::class);
        $middleware->appendToPriorityList(InitializeTenancy::class, SetUpPanel::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A half-typed bill is expensive. If the CSRF token expired while a car
        // sat on the lift, hand the counter their input back instead of the
        // stock "Page Expired" screen, which would discard the whole cart.
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->isMethod('POST') && ! $request->expectsJson()) {
                return back()
                    ->withInput()
                    ->with('status', 'Your session had timed out and was refreshed. Please press save again.');
            }
        });
    })->create();
