<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

/**
 * Deliberately a plain PHPUnit test: it never boots the application or opens
 * a connection, so it proves the refusal even where the guard blocks every
 * framework test.
 */
class TestDatabaseGuardTest extends TestCase
{
    private const LOCAL_DATABASE = 'coffee_delivery_leonidas';

    public function test_it_refuses_the_normal_local_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local database "coffee_delivery_leonidas"');

        TestDatabaseGuard::assertSafe(null, 'testing', ['driver' => 'mysql', 'database' => self::LOCAL_DATABASE], self::LOCAL_DATABASE);
    }

    public static function unsafeSetups(): array
    {
        $local = ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => self::LOCAL_DATABASE];
        $memory = ['driver' => 'sqlite', 'database' => ':memory:'];

        return [
            'local database without an .env file' => [null, 'testing', $local, null],
            'local database hidden behind DB_URL' => [null, 'testing', ['driver' => 'mysql', 'url' => 'mysql://root@127.0.0.1:3306/'.self::LOCAL_DATABASE, 'database' => 'leonidas_test'], self::LOCAL_DATABASE],
            'cached local configuration' => ['/app/bootstrap/cache/config.php', 'local', $local, self::LOCAL_DATABASE],
            'cached configuration that resolves to sqlite' => ['/app/bootstrap/cache/config.php', 'testing', $memory, null],
            'non-testing environment' => [null, 'local', $memory, null],
            'sqlite database file' => [null, 'testing', ['driver' => 'sqlite', 'database' => '/app/database/database.sqlite'], null],
        ];
    }

    #[DataProvider('unsafeSetups')]
    public function test_it_refuses_anything_but_a_designated_test_database(?string $cachedConfig, string $environment, array $connection, ?string $localDatabase): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run tests');

        TestDatabaseGuard::assertSafe($cachedConfig, $environment, $connection, $localDatabase);
    }

    public static function safeSetups(): array
    {
        return [
            'phpunit.xml in-memory sqlite' => [['driver' => 'sqlite', 'url' => '', 'database' => ':memory:']],
            'disposable MariaDB concurrency database' => [['driver' => 'mysql', 'database' => 'leonidas_checkout_test']],
        ];
    }

    #[DataProvider('safeSetups')]
    public function test_it_allows_a_designated_test_database(array $connection): void
    {
        TestDatabaseGuard::assertSafe(null, 'testing', $connection, self::LOCAL_DATABASE);

        $this->addToAssertionCount(1);
    }

    public function test_it_reads_the_local_database_name_from_the_env_file(): void
    {
        $envFile = (string) tempnam(sys_get_temp_dir(), 'guard-env-');

        try {
            file_put_contents($envFile, "APP_ENV=local\nDB_DATABASE=".self::LOCAL_DATABASE."\n");

            $this->assertSame(self::LOCAL_DATABASE, TestDatabaseGuard::localDatabaseName($envFile));
            $this->assertNull(TestDatabaseGuard::localDatabaseName($envFile.'-missing'));
        } finally {
            @unlink($envFile);
        }
    }
}
