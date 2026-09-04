<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `session.secure` decides whether the one cookie that is valid on every shop
 * subdomain may travel over plaintext, so its default is worth pinning down.
 */
class SessionCookieSecurityTest extends TestCase
{
    public function test_the_cookie_is_secure_by_default_outside_local_and_testing(): void
    {
        $secure = $this->sessionSecureFor('production');

        $this->assertTrue($secure);
    }

    public function test_local_and_testing_keep_the_cookie_usable_over_http(): void
    {
        $this->assertFalse($this->sessionSecureFor('local'));
        $this->assertFalse($this->sessionSecureFor('testing'));
    }

    public function test_an_explicit_environment_value_overrides_the_default(): void
    {
        $secure = $this->sessionSecureFor('production', 'false');

        $this->assertFalse($secure);
    }

    /**
     * Re-evaluate the config file under a chosen environment. The value is
     * computed at boot from `env()`, so reading `config()` here would only
     * report the environment the suite itself runs in.
     */
    private function sessionSecureFor(string $environment, ?string $secureCookie = null): bool
    {
        $originalEnvironment = $_ENV['APP_ENV'] ?? null;
        $originalSecureCookie = $_ENV['SESSION_SECURE_COOKIE'] ?? null;

        $_ENV['APP_ENV'] = $environment;

        if ($secureCookie === null) {
            unset($_ENV['SESSION_SECURE_COOKIE']);
        } else {
            $_ENV['SESSION_SECURE_COOKIE'] = $secureCookie;
        }

        try {
            $configuration = require config_path('session.php');

            return $configuration['secure'];
        } finally {
            $this->restoreEnvironmentValue('APP_ENV', $originalEnvironment);
            $this->restoreEnvironmentValue('SESSION_SECURE_COOKIE', $originalSecureCookie);
        }
    }

    private function restoreEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);

            return;
        }

        $_ENV[$key] = $value;
    }
}
