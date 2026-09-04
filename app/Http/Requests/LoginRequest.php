<?php

namespace App\Http\Requests;

use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS_PER_USERNAME = 5;

    private const MAX_ATTEMPTS_PER_ADDRESS = 20;

    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // is_active is part of the credentials, so a deactivated account fails
        // exactly like a wrong password — no hint that the username is real.
        $credentials = [
            'username' => $this->string('username')->toString(),
            'password' => $this->string('password')->toString(),
            'is_active' => true,
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->addressThrottleKey());

            throw ValidationException::withMessages([
                'username' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->addressThrottleKey());
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        $exhaustedKey = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS_PER_USERNAME) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->addressThrottleKey(), self::MAX_ATTEMPTS_PER_ADDRESS) => $this->addressThrottleKey(),
            default => null,
        };

        if ($exhaustedKey === null) {
            return;
        }

        Event::dispatch(new Lockout($this));

        $seconds = RateLimiter::availableIn($exhaustedKey);

        throw ValidationException::withMessages([
            'username' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * A spray across many usernames from one address never exhausts the
     * per-username bucket, because each fresh username starts its own. This
     * second bucket is the one that stops it.
     */
    protected function addressThrottleKey(): string
    {
        return Str::transliterate(
            'address|'.resolve(TenantContext::class)->id().'|'.$this->ip()
        );
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(
            resolve(TenantContext::class)->id().'|'.
            Str::lower($this->string('username')->toString()).'|'.$this->ip()
        );
    }
}
