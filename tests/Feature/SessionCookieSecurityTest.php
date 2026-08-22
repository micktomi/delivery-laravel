<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The config file is re-evaluated against a swapped environment rather than
 * read off the booted app, because what is under test is the fallback the
 * closure picks when the variable is absent.
 */
class SessionCookieSecurityTest extends TestCase
{
    public function test_production_without_the_variable_secures_the_cookie(): void
    {
        $this->assertTrue($this->sessionSecureFor('production', null));
    }

    public function test_local_without_the_variable_leaves_the_cookie_open(): void
    {
        $this->assertFalse($this->sessionSecureFor('local', null));
    }

    public function test_a_missing_app_env_is_read_as_production(): void
    {
        $this->assertTrue($this->sessionSecureFor(null, null));
    }

    public function test_an_explicit_false_overrides_the_production_default(): void
    {
        $this->assertFalse($this->sessionSecureFor('production', 'false'));
    }

    public function test_an_explicit_true_overrides_the_local_default(): void
    {
        $this->assertTrue($this->sessionSecureFor('local', 'true'));
    }

    private function sessionSecureFor(?string $appEnv, ?string $secureCookie): mixed
    {
        $original = [
            'APP_ENV' => $_ENV['APP_ENV'] ?? null,
            'SESSION_SECURE_COOKIE' => $_ENV['SESSION_SECURE_COOKIE'] ?? null,
        ];

        try {
            $this->setEnvValue('APP_ENV', $appEnv);
            $this->setEnvValue('SESSION_SECURE_COOKIE', $secureCookie);

            return (require config_path('session.php'))['secure'];
        } finally {
            foreach ($original as $key => $value) {
                $this->setEnvValue($key, $value);
            }
        }
    }

    private function setEnvValue(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}
