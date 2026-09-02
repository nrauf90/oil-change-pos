<?php

use App\Http\Middleware\EnforceReadOnlySupportAccess;
use App\Http\Middleware\EnsureFilamentActionMatchesTenant;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Http\Middleware\EnsureShopIsActive;
use App\Http\Middleware\InitializeSupportAccess;
use App\Http\Middleware\InitializeTenancy;
use App\Http\Middleware\TenantThrottleRequests;
use App\Tenancy\TenantPackageRouteRegistrar;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Filament\Http\Middleware\AuthenticateSession as FilamentAuthenticateSession;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: static function (): void {
            resolve(TenantPackageRouteRegistrar::class)->register();
        },
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts();
        $middleware->append(InitializeTenancy::class);
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'module' => EnsureModuleIsEnabled::class,
            'shop.active' => EnsureShopIsActive::class,
            'tenant' => InitializeTenancy::class,
            'support.access' => InitializeSupportAccess::class,
            'support.readonly' => EnforceReadOnlySupportAccess::class,
            'throttle' => TenantThrottleRequests::class,
        ]);
        $middleware->appendToPriorityList(StartSession::class, EnsureShopIsActive::class);
        $middleware->appendToPriorityList(EnsureShopIsActive::class, InitializeTenancy::class);
        $middleware->appendToPriorityList(InitializeTenancy::class, EnsureFilamentActionMatchesTenant::class);
        $middleware->appendToPriorityList(EnsureFilamentActionMatchesTenant::class, SubstituteBindings::class);
        $middleware->appendToPriorityList(InitializeTenancy::class, SetUpPanel::class);
        $middleware->prependToPriorityList(EnsureShopIsActive::class, InitializeSupportAccess::class);
        $middleware->prependToPriorityList([
            EnsureFilamentActionMatchesTenant::class,
            SetUpPanel::class,
            PermissionMiddleware::class,
            FilamentAuthenticateSession::class,
            FilamentAuthenticate::class,
            Authenticate::class,
            SubstituteBindings::class,
        ], EnforceReadOnlySupportAccess::class);
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
