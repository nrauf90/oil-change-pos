<?php

namespace App\Tenancy;

use App\Enums\ShopStatus;
use App\Http\Middleware\InitializeTenancy;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopAccessSession;
use App\Support\PlatformSessionAuthentication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class SupportAccessManager
{
    public const SESSION_KEY = 'platform.support_access';

    public function __construct(
        private Request $request,
        private AuthManager $auth,
        private CookieJar $cookies,
        private PlatformSessionAuthentication $platformAuthentication,
        private SupportAccessContext $context,
        private ConfigRepository $config,
    ) {}

    public function start(PlatformUser $user, Shop $shop, ?string $reason): ShopAccessSession
    {
        $this->assertProductionSessionDomain();

        if (! $this->request->hasSession()) {
            throw new RuntimeException('Support access requires a server-side session.');
        }

        $session = $this->request->session();

        return DB::connection('central')->transaction(function () use ($user, $shop, $reason, $session): ShopAccessSession {
            [$platformUser, $activeShop] = $this->authorizedStartSubjects($user, $shop, true);
            $activeAudits = ShopAccessSession::query()
                ->where('platform_user_id', $platformUser->getKey())
                ->whereNull('ended_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($activeAudits as $activeAudit) {
                $activeAudit->end();
            }

            $session->forget(self::SESSION_KEY);
            $this->forgetTenantIdentity($this->request);

            $audit = ShopAccessSession::start(
                platformUser: $platformUser,
                shop: $activeShop,
                reason: $reason,
                ipAddress: $this->request->ip(),
                userAgent: $this->request->userAgent(),
            );

            try {
                $session->put(self::SESSION_KEY, [
                    'audit_id' => $audit->getKey(),
                    'shop_id' => $activeShop->getKey(),
                ]);
                $session->regenerate(true);
            } catch (Throwable $exception) {
                $session->forget(self::SESSION_KEY);

                throw $exception;
            }

            return $audit;
        });
    }

    public function end(): void
    {
        if (! $this->request->hasSession()) {
            return;
        }

        $audit = $this->context->active()
            ? $this->context->audit()
            : null;

        if ($audit instanceof ShopAccessSession) {
            $audit->end();
        }

        $this->context->clear();
        $this->request->session()->forget(self::SESSION_KEY);
        $this->forgetTenantIdentity($this->request);
        $this->request->session()->regenerate(true);
    }

    public function tenantEntryUrl(Shop $shop): string
    {
        $origin = $this->configuredOrigin();

        if (in_array((string) $this->config->get('app.env'), ['local', 'testing'], true)) {
            return $origin.'/admin';
        }

        $parts = parse_url($origin);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;

        if (! is_string($scheme) || ! is_string($host)) {
            throw new RuntimeException('The configured application URL is not a valid central origin.');
        }

        return $scheme.'://'.$shop->slug.'.'.$host
            .(is_int($port) ? ':'.$port : '')
            .'/admin';
    }

    public function centralExitUrl(): string
    {
        $path = '/platform';

        if ($this->context->active()) {
            $shop = $this->context->shop();

            if (Shop::query()->whereKey($shop->getKey())->exists()) {
                $path = route(
                    'filament.platform.resources.shops.view',
                    ['record' => $shop->getKey()],
                    false,
                );
            }
        }

        return $this->configuredOrigin().'/'.ltrim($path, '/');
    }

    public function resume(Request $request): ?SupportAccessContext
    {
        if (! $request->hasSession()) {
            return null;
        }

        $state = $request->session()->get(self::SESSION_KEY);

        if ($state === null) {
            return null;
        }

        if (! is_array($state)
            || array_keys($state) !== ['audit_id', 'shop_id']
            || ! is_string($state['audit_id'])
            || ! is_string($state['shop_id'])) {
            $this->invalidateLocalState($request);

            return null;
        }

        $authenticatedUser = $this->auth->guard('platform')->user();
        $audit = ShopAccessSession::query()->whereKey($state['audit_id'])->first();

        if (! $authenticatedUser instanceof PlatformUser
            || $authenticatedUser::class !== PlatformUser::class) {
            $this->invalidateLocalState($request);

            return null;
        }

        $platformUser = PlatformUser::query()
            ->whereKey($authenticatedUser->getKey())
            ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->first();

        if (! $platformUser instanceof PlatformUser
            || ! $this->platformAuthentication->currentSessionMatches($platformUser)) {
            $this->invalidateLocalState(
                $request,
                $this->ownedActiveAudit($audit, $authenticatedUser),
            );

            return null;
        }

        if (! $audit instanceof ShopAccessSession
            || $audit->ended_at !== null
            || ! hash_equals((string) $audit->platform_user_id, (string) $platformUser->getKey())) {
            $this->invalidateLocalState($request);

            return null;
        }

        if (! hash_equals((string) $audit->shop_id, $state['shop_id'])) {
            $this->invalidateLocalState($request, $audit);

            return null;
        }

        if ($this->hasOutlivedItsGrant($audit)) {
            $this->invalidateLocalState($request, $audit);

            return null;
        }

        $shop = Shop::query()->whereKey($state['shop_id'])->first();

        if (! $shop instanceof Shop) {
            $this->invalidateLocalState($request, $audit);

            return null;
        }

        if ($shop->status !== ShopStatus::Active) {
            $this->invalidateLocalState($request, $audit);
            $this->context->markShopUnavailable();

            return null;
        }

        $this->auth->guard('platform')->setUser($platformUser);
        $this->context->activate($audit, $shop, $platformUser);

        return $this->context;
    }

    /**
     * Has this grant run past its ceiling?
     *
     * An operator who closes the tab never exits, so nothing else would ever
     * end the audit: the sliding session keeps read access alive and the row
     * reads "Active" indefinitely, hiding a live session among stale ones.
     */
    private function hasOutlivedItsGrant(ShopAccessSession $audit): bool
    {
        $maxLifetime = (int) config('auth.support_access.max_lifetime_minutes');

        if ($maxLifetime <= 0) {
            return false;
        }

        $startedAt = $audit->started_at;

        return $startedAt !== null && $startedAt->copy()->addMinutes($maxLifetime)->isPast();
    }

    /** @return array{PlatformUser, Shop} */
    private function authorizedStartSubjects(
        PlatformUser $user,
        Shop $shop,
        bool $lockForUpdate = false,
    ): array {
        $authenticatedUser = $this->auth->guard('platform')->user();

        if ($user::class !== PlatformUser::class
            || ! $authenticatedUser instanceof PlatformUser
            || $authenticatedUser::class !== PlatformUser::class
            || ! hash_equals((string) $authenticatedUser->getKey(), (string) $user->getKey())) {
            throw new AuthorizationException('Active platform administrator authentication is required.');
        }

        $platformUserQuery = PlatformUser::query()
            ->whereKey($user->getKey())
            ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
            ->where('is_active', true);

        if ($lockForUpdate) {
            $platformUserQuery->lockForUpdate();
        }

        $platformUser = $platformUserQuery->first();

        if (! $platformUser instanceof PlatformUser
            || ! $this->platformAuthentication->currentSessionMatches($platformUser)) {
            throw new AuthorizationException('Active platform administrator authentication is required.');
        }

        $activeShopQuery = Shop::query()
            ->whereKey($shop->getKey())
            ->where('status', ShopStatus::Active);

        if ($lockForUpdate) {
            $activeShopQuery->lockForUpdate();
        }

        $activeShop = $activeShopQuery->first();

        if ($shop::class !== Shop::class || ! $activeShop instanceof Shop) {
            throw new AuthorizationException('Support access is available only for active shops.');
        }

        $this->auth->guard('platform')->setUser($platformUser);

        return [$platformUser, $activeShop];
    }

    private function ownedActiveAudit(
        ?ShopAccessSession $audit,
        PlatformUser $platformUser,
    ): ?ShopAccessSession {
        if (! $audit instanceof ShopAccessSession
            || $audit->ended_at !== null
            || ! hash_equals((string) $audit->platform_user_id, (string) $platformUser->getKey())) {
            return null;
        }

        return $audit;
    }

    private function invalidateLocalState(
        Request $request,
        ?ShopAccessSession $ownedAudit = null,
    ): void {
        if ($ownedAudit instanceof ShopAccessSession) {
            $ownedAudit->end();
        }

        $this->context->clear();
        $request->session()->forget(self::SESSION_KEY);
        $this->forgetTenantIdentity($request);
        $request->session()->regenerate(true);
    }

    private function forgetTenantIdentity(Request $request): void
    {
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
    }

    private function configuredOrigin(): string
    {
        $origin = rtrim((string) $this->config->get('app.url'), '/');
        $parts = parse_url($origin);

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('The configured application URL is not a valid central origin.');
        }

        return $origin;
    }

    private function assertProductionSessionDomain(): void
    {
        if (in_array((string) $this->config->get('app.env'), ['local', 'testing'], true)) {
            return;
        }

        $originHost = parse_url($this->configuredOrigin(), PHP_URL_HOST);
        $sessionDomain = $this->config->get('session.domain');
        $normalizedSessionDomain = is_string($sessionDomain)
            ? ltrim(strtolower(trim($sessionDomain)), '.')
            : '';

        if (! is_string($originHost)
            || ! hash_equals(strtolower($originHost), $normalizedSessionDomain)) {
            throw new RuntimeException(
                'Support access requires SESSION_DOMAIN to match the central application host.',
            );
        }
    }
}
