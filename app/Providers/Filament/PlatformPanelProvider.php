<?php

namespace App\Providers\Filament;

use App\Filament\Platform\Pages\PlatformDashboard;
use App\Http\Middleware\EnsureCentralHost;
use App\Http\Middleware\EnsureFreshPlatformAuthentication;
use App\Http\Middleware\UsePlatformGuard;
use App\Models\Central\PlatformUser;
use App\Support\PlatformSessionAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PlatformPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        Event::listen(Login::class, static function (Login $event): void {
            if ($event->guard === 'platform' && $event->user instanceof PlatformUser) {
                resolve(PlatformSessionAuthentication::class)->recordLogin($event->user);
            }
        });
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('platform')
            ->login()
            ->authGuard('platform')
            ->brandName(config('app.name').' Platform')
            ->brandLogo(fn (): Htmlable => new HtmlString(
                view('filament.platform.components.brand')->render(),
            ))
            ->brandLogoHeight('3rem')
            ->simplePageMaxContentWidth(Width::Small)
            ->viteTheme('resources/css/filament/platform/theme.css')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\Filament\Platform\Resources')
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\Filament\Platform\Pages')
            ->pages([
                PlatformDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Platform/Widgets'), for: 'App\Filament\Platform\Widgets')
            ->middleware([
                EnsureCentralHost::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                UsePlatformGuard::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->persistentMiddleware([
                EnsureCentralHost::class,
                UsePlatformGuard::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureFreshPlatformAuthentication::class,
            ], isPersistent: true);
    }
}
