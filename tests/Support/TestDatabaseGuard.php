<?php

namespace Tests\Support;

use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

/**
 * Refuses to hand the application to a test unless its default connection is
 * a disposable test database.
 *
 * RefreshDatabase runs migrate:fresh on whatever the default connection
 * resolves to. When the phpunit.xml overrides are ignored (a cached config
 * file, or DB_* variables exported in the shell) that is the developer's real
 * MySQL database, and the suite wipes it while still passing.
 */
final class TestDatabaseGuard
{
    /**
     * Disposable server databases follow the same convention as the opt-in
     * *_checkout_test / *_terminal_test integration databases.
     */
    private const DISPOSABLE_DATABASE = '/^[A-Za-z0-9_]+_test$/D';

    public static function check(Application $app): void
    {
        $cachedConfig = $app->getCachedConfigPath();
        $default = $app['config']->get('database.default');

        self::assertSafe(
            is_file($cachedConfig) ? $cachedConfig : null,
            (string) $app->environment(),
            (array) $app['config']->get("database.connections.{$default}", []),
            self::localDatabaseName($app->basePath('.env')),
        );
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    public static function assertSafe(?string $cachedConfig, string $environment, array $connection, ?string $localDatabase): void
    {
        // Checked on disk rather than via configurationIsCached(): Laravel's
        // in-memory WithCachedConfig is built from the testing environment.
        if ($cachedConfig !== null) {
            self::refuse("configuration is cached in {$cachedConfig}, so the phpunit.xml database overrides are ignored. Run `php artisan config:clear` first.");
        }

        if ($environment !== 'testing') {
            self::refuse("APP_ENV resolved to \"{$environment}\" instead of \"testing\".");
        }

        // Resolve DB_URL the same way DatabaseManager does, since it wins over 'database'.
        $connection = (new ConfigurationUrlParser)->parseConfiguration($connection);
        $driver = (string) ($connection['driver'] ?? '');
        $database = (string) ($connection['database'] ?? '');

        if ($localDatabase !== null && $database === $localDatabase) {
            self::refuse("the default connection points at the local database \"{$database}\" from .env.");
        }

        $disposable = $driver === 'sqlite'
            ? $database === ':memory:'
            : preg_match(self::DISPOSABLE_DATABASE, $database) === 1;

        if (! $disposable) {
            self::refuse("the {$driver} database \"{$database}\" is not a designated test database (sqlite :memory: or a name ending in _test).");
        }
    }

    public static function localDatabaseName(string $envFile): ?string
    {
        if (! is_file($envFile)) {
            return null;
        }

        $name = Dotenv::parse((string) file_get_contents($envFile))['DB_DATABASE'] ?? '';

        return $name === '' ? null : $name;
    }

    private static function refuse(string $reason): never
    {
        throw new RuntimeException('Refusing to run tests: '.$reason);
    }
}
