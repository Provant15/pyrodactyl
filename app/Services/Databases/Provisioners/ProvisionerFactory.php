<?php

namespace Pterodactyl\Services\Databases\Provisioners;

use InvalidArgumentException;
use Illuminate\Contracts\Container\Container;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Contracts\Database\DatabaseProvisionerInterface;

/**
 * Resolves the correct database provisioner implementation for a given host.
 *
 * Uses the host's `driver` column to determine which provisioner to instantiate.
 * Defaults to MySQL for hosts without an explicit driver (backwards compatibility).
 */
class ProvisionerFactory
{
    /** @var array<string, class-string<DatabaseProvisionerInterface>> */
    private const DRIVER_MAP = [
        'mysql' => MysqlProvisioner::class,
        'pgsql' => PostgresProvisioner::class,
    ];

    public function __construct(
        private Container $container,
    ) {}

    /**
     * Resolve a provisioner instance for the given database host.
     *
     * Falls back to 'mysql' when the host has no explicit driver set,
     * maintaining backwards compatibility with existing database hosts
     * that predate the multi-driver feature.
     *
     * @param DatabaseHost $host The host whose driver determines the provisioner
     *
     * @return DatabaseProvisionerInterface
     *
     * @throws InvalidArgumentException If the host's driver is not supported
     */
    public function forHost(DatabaseHost $host): DatabaseProvisionerInterface
    {
        $driver = $host->driver ?? 'mysql';

        if (!isset(self::DRIVER_MAP[$driver])) {
            throw new InvalidArgumentException("Unsupported database driver: {$driver}");
        }

        return $this->container->make(self::DRIVER_MAP[$driver]);
    }
}
