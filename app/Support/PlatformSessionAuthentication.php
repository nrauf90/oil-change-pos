<?php

namespace App\Support;

use App\Models\Central\PlatformUser;
use App\Tenancy\SupportAccessManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use JsonException;

class PlatformSessionAuthentication
{
    private const GENERATION_SESSION_KEY = 'auth_generation_platform';

    public function recordLogin(PlatformUser $platformUser): void
    {
        $validatedPasswordHash = (string) $platformUser->getAuthPassword();
        $attributes = $platformUser->getConnection()->transaction(function () use (
            $platformUser,
            $validatedPasswordHash,
        ): array {
            $freshPlatformUser = PlatformUser::on($platformUser->getConnectionName())
                ->whereKey($platformUser->getKey())
                ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $freshPlatformUser instanceof PlatformUser
                || ! hash_equals((string) $freshPlatformUser->getAuthPassword(), $validatedPasswordHash)) {
                return [];
            }

            if (blank($freshPlatformUser->getRememberToken())) {
                $freshPlatformUser->setRememberToken(Str::random(60));
            }

            $freshPlatformUser->forceFill(['last_login_at' => now()])->save();

            return $freshPlatformUser->getAttributes();
        });

        if ($attributes === []) {
            $this->forgetCurrentAuthentication($platformUser->getKey());

            return;
        }

        $platformUser->setRawAttributes($attributes, true);
        $this->guard()->getSession()->put(
            self::GENERATION_SESSION_KEY,
            $this->generation($platformUser),
        );
    }

    public function currentSessionMatches(PlatformUser $platformUser): bool
    {
        $guard = $this->guard();
        $session = $guard->getSession();

        if (! $session->has($guard->getName())) {
            return true;
        }

        $generation = $session->get(self::GENERATION_SESSION_KEY);

        return is_string($generation)
            && hash_equals($this->generation($platformUser), $generation);
    }

    public function revokePersistedSessions(int|string $platformUserKey, string $connectionName): void
    {
        $connection = app('db')->connection($connectionName);
        $guard = $this->guard();
        $platformLoginKey = $guard->getName();
        $webLoginKey = Auth::guard('web')->getName();

        $connection->table((string) config('session.table', 'sessions'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'payload'])
            ->each(function (object $session) use (
                $connection,
                $platformLoginKey,
                $platformUserKey,
                $webLoginKey,
            ): void {
                $payload = $this->decodePayload($session->payload);

                if ($payload === null
                    || ! array_key_exists($platformLoginKey, $payload)
                    || (string) $payload[$platformLoginKey] !== (string) $platformUserKey) {
                    return;
                }

                unset(
                    $payload[$platformLoginKey],
                    $payload[self::GENERATION_SESSION_KEY],
                    $payload['password_hash_platform'],
                    $payload[SupportAccessManager::SESSION_KEY],
                );

                $connection->table((string) config('session.table', 'sessions'))
                    ->where('id', $session->id)
                    ->update([
                        'payload' => $this->encodePayload($payload),
                        'user_id' => $payload[$webLoginKey] ?? null,
                    ]);
            });
    }

    public function forgetCurrentAuthentication(int|string $platformUserKey): void
    {
        $guard = $this->guard();
        $session = $guard->getSession();

        if ((string) $session->get($guard->getName()) !== (string) $platformUserKey) {
            return;
        }

        $guard->logoutCurrentDevice();
        $session->forget([
            self::GENERATION_SESSION_KEY,
            'password_hash_platform',
            SupportAccessManager::SESSION_KEY,
        ]);
    }

    private function guard(): SessionGuard
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('platform');

        return $guard;
    }

    private function generation(PlatformUser $platformUser): string
    {
        return hash_hmac(
            'sha256',
            (string) $platformUser->getRememberToken(),
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
