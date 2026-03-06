<?php

namespace Pterodactyl\Tests\Unit\Extensions;

use Mockery;
use Mockery\MockInterface;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Tests\TestCase;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Config\Repository as ConfigRepository;
use Pterodactyl\Contracts\Repository\DatabaseHostRepositoryInterface;

/**
 * Verifies that DynamicDatabaseConnection builds the correct runtime
 * database configuration array for each supported driver (MySQL, PostgreSQL).
 */
class DynamicDatabaseConnectionTest extends TestCase
{
    private MockInterface&ConfigRepository $config;
    private MockInterface&Encrypter $encrypter;
    private MockInterface&DatabaseHostRepositoryInterface $repository;
    private DynamicDatabaseConnection $connection;

    /**
     * Set up test dependencies with Mockery mocks.
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->config = Mockery::mock(ConfigRepository::class);
        $this->encrypter = Mockery::mock(Encrypter::class);
        $this->repository = Mockery::mock(DatabaseHostRepositoryInterface::class);

        $this->connection = new DynamicDatabaseConnection(
            $this->config,
            $this->encrypter,
            $this->repository,
        );
    }

    /**
     * Verify that a MySQL host produces a config with charset, collation,
     * and defaults to the 'mysql' administrative database.
     */
    public function testMysqlHostProducesCorrectConfig(): void
    {
        $host = $this->makeHost(['driver' => 'mysql']);

        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->with('encrypted_password')
            ->andReturn('decrypted_password');

        $this->config->shouldReceive('set')
            ->once()
            ->with('database.connections.dynamic', [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'mysql',
                'username' => 'admin',
                'password' => 'decrypted_password',
                'charset' => 'utf8',
                'collation' => 'utf8_unicode_ci',
            ]);

        $this->connection->set('dynamic', $host);
    }

    /**
     * Verify that a PostgreSQL host produces a config with sslmode,
     * search_path, no collation, and defaults to the 'postgres' database.
     */
    public function testPgsqlHostProducesCorrectConfig(): void
    {
        $host = $this->makeHost(['driver' => 'pgsql', 'port' => 5432]);

        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->with('encrypted_password')
            ->andReturn('decrypted_password');

        $this->config->shouldReceive('set')
            ->once()
            ->with('database.connections.dynamic', [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 5432,
                'database' => 'postgres',
                'username' => 'admin',
                'password' => 'decrypted_password',
                'charset' => 'utf8',
                'sslmode' => 'prefer',
                'search_path' => 'public',
            ]);

        $this->connection->set('dynamic', $host);
    }

    /**
     * Verify that a custom database name overrides the default for MySQL.
     */
    public function testCustomDatabaseOverridesDefaultForMysql(): void
    {
        $host = $this->makeHost(['driver' => 'mysql']);

        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->andReturn('decrypted_password');

        $this->config->shouldReceive('set')
            ->once()
            ->with('database.connections.dynamic', Mockery::on(function (array $config) {
                return $config['database'] === 'my_custom_db'
                    && $config['driver'] === 'mysql';
            }));

        $this->connection->set('dynamic', $host, 'my_custom_db');
    }

    /**
     * Verify that a custom database name overrides the default for PostgreSQL.
     */
    public function testCustomDatabaseOverridesDefaultForPgsql(): void
    {
        $host = $this->makeHost(['driver' => 'pgsql', 'port' => 5432]);

        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->andReturn('decrypted_password');

        $this->config->shouldReceive('set')
            ->once()
            ->with('database.connections.dynamic', Mockery::on(function (array $config) {
                return $config['database'] === 'my_custom_db'
                    && $config['driver'] === 'pgsql';
            }));

        $this->connection->set('dynamic', $host, 'my_custom_db');
    }

    /**
     * Verify that passing an integer host ID triggers a repository lookup.
     */
    public function testHostLoadedByIdCallsRepository(): void
    {
        $host = $this->makeHost(['driver' => 'mysql']);

        $this->repository->shouldReceive('find')
            ->once()
            ->with(42)
            ->andReturn($host);

        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->andReturn('decrypted_password');

        $this->config->shouldReceive('set')
            ->once()
            ->with('database.connections.dynamic', Mockery::on(function (array $config) {
                return $config['driver'] === 'mysql'
                    && $config['database'] === 'mysql';
            }));

        $this->connection->set('dynamic', 42);
    }

    /**
     * Verify that a host without a driver set defaults to MySQL behaviour.
     */
    public function testNullDriverDefaultsToMysql(): void
    {
        $host = $this->makeHost();
        // driver is not set, so $host->driver returns null

        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->andReturn('decrypted_password');

        $this->config->shouldReceive('set')
            ->once()
            ->with('database.connections.dynamic', Mockery::on(function (array $config) {
                return $config['driver'] === 'mysql'
                    && $config['collation'] === 'utf8_unicode_ci'
                    && $config['database'] === 'mysql';
            }));

        $this->connection->set('dynamic', $host);
    }

    /**
     * Verify that an unsupported driver throws an InvalidArgumentException.
     */
    public function testUnsupportedDriverThrowsException(): void
    {
        $host = $this->makeHost(['driver' => 'sqlite']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver: sqlite');

        $this->connection->set('dynamic', $host);
    }

    /**
     * Create a DatabaseHost model instance populated with test attributes.
     *
     * @param array<string, mixed> $attributes Additional or override attributes.
     */
    private function makeHost(array $attributes = []): DatabaseHost
    {
        $host = new DatabaseHost();
        $host->forceFill(array_merge([
            'id' => 1,
            'name' => 'Test Host',
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'admin',
            'password' => 'encrypted_password',
            'max_databases' => 10,
        ], $attributes));

        return $host;
    }
}
