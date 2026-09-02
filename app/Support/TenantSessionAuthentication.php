<?php

namespace App\Support;

use App\Http\Middleware\InitializeTenancy;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final readonly class TenantSessionAuthentication
{
    public const GENERATION_SESSION_KEY = 'auth_generation_web';

    public function __construct(
        private AuthManager $auth,
        private DatabaseManager $database,
    ) {}

    public function recordLogin(User $user): bool
    {
        $attributes = $user->getConnection()->transaction(function () use ($user): array {
            $freshUser = User::on($user->getConnectionName())
                ->whereKey($user->getKey())
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $freshUser instanceof User) {
                return [];
            }

            if (blank($freshUser->getRememberToken())) {
                $freshUser->setRememberToken(Str::random(60));
            }

            $freshUser->forceFill(['last_login_at' => now()])->save();

            return $freshUser->getAttributes();
        });

        if ($attributes === []) {
            $this->forgetCurrentAuthentication($user->getKey());

            return false;
        }

        $user->setRawAttributes($attributes, true);
        $this->guard()->getSession()->put(
            self::GENERATION_SESSION_KEY,
            $this->generation($user),
        );

        return true;
    }

    public function recordRememberedAuthentication(User $user): void
    {
        $this->guard()->getSession()->put(
            self::GENERATION_SESSION_KEY,
            $this->generation($user),
        );
    }

    public function currentSessionMatches(User $user): bool
    {
        $guard = $this->guard();
        $session = $guard->getSession();

        if (! $session->has($guard->getName())) {
            return true;
        }

        $generation = $session->get(self::GENERATION_SESSION_KEY);

        return is_string($generation)
            && hash_equals($this->generation($user), $generation);
    }

    public function ensureCurrentAuthentication(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $guard = $this->guard();
        $session = $request->session();
        $hasAuthentication = $session->has($guard->getName())
            || $request->cookies->has($guard->getRecallerName());

        if (! $hasAuthentication) {
            return;
        }

        $user = $guard->user();

        if (! $user instanceof User) {
            $this->forgetCurrentAuthentication();

            return;
        }

        if ($guard->viaRemember()) {
            $this->recordRememberedAuthentication($user);

            return;
        }

        if (! $this->currentSessionMatches($user)) {
            $this->forgetCurrentAuthentication($user->getKey());
        }
    }

    public function revokePersistedSessions(string $shopKey, int|string $userKey): void
    {
        $connectionName = config('session.connection');
        $connection = $this->database->connection(
            is_string($connectionName) && $connectionName !== '' ? $connectionName : 'central',
        );
        $webLoginKey = $this->guard()->getName();

        $connection->transaction(function () use ($connection, $shopKey, $userKey, $webLoginKey): void {
            $connection->table((string) config('session.table', 'sessions'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'payload'])
                ->each(function (object $session) use ($connection, $shopKey, $userKey, $webLoginKey): void {
                    $payload = $this->decodePayload($session->payload);

                    if ($payload === null
                        || ! array_key_exists(InitializeTenancy::SESSION_SHOP_KEY, $payload)
                        || ! array_key_exists($webLoginKey, $payload)
                        || ! is_string($payload[InitializeTenancy::SESSION_SHOP_KEY])
                        || (! is_int($payload[$webLoginKey]) && ! is_string($payload[$webLoginKey]))
                        || ! hash_equals($shopKey, $payload[InitializeTenancy::SESSION_SHOP_KEY])
                        || (string) $payload[$webLoginKey] !== (string) $userKey) {
                        return;
                    }

                    unset(
                        $payload[$webLoginKey],
                        $payload[self::GENERATION_SESSION_KEY],
                        $payload['password_hash_web'],
                        $payload[InitializeTenancy::SESSION_SHOP_KEY],
                    );

                    $connection->table((string) config('session.table', 'sessions'))
                        ->where('id', $session->id)
                        ->update([
                            'payload' => $this->encodePayload($payload),
                            'user_id' => null,
                        ]);
                });
        });
    }

    private function guard(): SessionGuard
    {
        $guard = $this->auth->guard('web');

        if (! $guard instanceof SessionGuard) {
            throw new RuntimeException('The tenant web guard must use session authentication.');
        }

        return $guard;
    }

    public function forgetCurrentAuthentication(int|string|null $userKey = null): void
    {
        $guard = $this->guard();
        $session = $guard->getSession();

        if ($userKey !== null
            && (string) $session->get($guard->getName()) !== (string) $userKey) {
            return;
        }

        $guard->logoutCurrentDevice();
        $session->forget([
            self::GENERATION_SESSION_KEY,
            'password_hash_web',
            InitializeTenancy::SESSION_SHOP_KEY,
        ]);
    }

    private function generation(User $user): string
    {
        return hash_hmac(
            'sha256',
            (string) $user->getRememberToken(),
            (string) config('app.key'),
        );
    }

    /** @return array<string, mixed>|null */
    private function decodePayload(mixed $encodedPayload): ?array
    {
        if (! is_string($encodedPayload)) {
            return null;
        }

        $serializedPayload = base64_decode($encodedPayload, true);

        if ($serializedPayload === false) {
            return null;
        }

        try {
            $payload = json_decode($serializedPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /** @param array<string, mixed> $payload */
    private function encodePayload(array $payload): string
    {
        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
