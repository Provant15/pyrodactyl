<?php

namespace Pterodactyl\Tests\Unit\Services\Databases\Provisioners;

use InvalidArgumentException;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Services\Databases\Provisioners\MysqlProvisioner;
use Pterodactyl\Services\Databases\Provisioners\PostgresProvisioner;
use Pterodactyl\Services\Databases\Provisioners\ProvisionerFactory;

/**
 * Verifies that ProvisionerFactory correctly resolves provisioner
 * implementations based on the DatabaseHost's driver attribute.
 */
class ProvisionerFactoryTest extends TestCase
{
    /**
     * Verify that a host with driver='mysql' resolves to MysqlProvisioner.
     */
    public function testReturnsMysqlProvisionerForMysqlDriver(): void
    {
        $factory = $this->app->make(ProvisionerFactory::class);
        $host = $this->makeHost(['driver' => 'mysql']);

        $provisioner = $factory->forHost($host);

        $this->assertInstanceOf(MysqlProvisioner::class, $provisioner);
    }

    /**
     * Verify that a host with no driver set defaults to MysqlProvisioner.
     */
    public function testDefaultsToMysqlWhenDriverIsNull(): void
    {
        $factory = $this->app->make(ProvisionerFactory::class);
        $host = $this->makeHost();
        // Ensure the driver property is not set (null via dynamic access).

        $provisioner = $factory->forHost($host);

        $this->assertInstanceOf(MysqlProvisioner::class, $provisioner);
    }

    /**
     * Verify that a host with driver='pgsql' resolves to PostgresProvisioner.
     */
    public function testReturnsPostgresProvisionerForPgsqlDriver(): void
    {
        $factory = $this->app->make(ProvisionerFactory::class);
        $host = $this->makeHost(['driver' => 'pgsql']);

        $provisioner = $factory->forHost($host);

        $this->assertInstanceOf(PostgresProvisioner::class, $provisioner);
    }

    /**
     * Verify that an unsupported driver throws an InvalidArgumentException.
     */
    public function testThrowsForUnsupportedDriver(): void
    {
        $factory = $this->app->make(ProvisionerFactory::class);
        $host = $this->makeHost(['driver' => 'sqlite']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver: sqlite');

        $factory->forHost($host);
    }

    /**
     * Create a DatabaseHost model instance populated with default attributes.
     *
     * @param array<string, mixed> $attributes
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
            'password' => 'encrypted_host_password',
            'max_databases' => 10,
        ], $attributes));

        return $host;
    }
}
