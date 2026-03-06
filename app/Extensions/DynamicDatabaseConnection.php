<?php

namespace Pterodactyl\Extensions;

use Pterodactyl\Models\DatabaseHost;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Config\Repository as ConfigRepository;
use Pterodactyl\Contracts\Repository\DatabaseHostRepositoryInterface;

/**
 * Registers dynamic database connections at runtime based on a DatabaseHost
 * model. Supports MySQL and PostgreSQL drivers with appropriate connection
 * parameters for each engine.
 */
class DynamicDatabaseConnection
{
    public function __construct(
        protected ConfigRepository $config,
        protected Encrypter $encrypter,
        protected DatabaseHostRepositoryInterface $repository,
    ) {
    }

    /**
     * Adds a dynamic database connection entry to the runtime config.
     *
     * Builds the connection array with driver-specific parameters:
     * - MySQL: charset, collation, default database 'mysql'
     * - PostgreSQL: charset, sslmode, search_path, default database 'postgres'
     *
     * @param string              $connection The Laravel connection name to register.
     * @param DatabaseHost|int    $host       A DatabaseHost model or its ID.
     * @param string|null         $database   Database name override. When null, the
     *                                        driver's administrative database is used.
     *
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     */
    public function set(string $connection, DatabaseHost|int $host, ?string $database = null): void
    {
        if (!$host instanceof DatabaseHost) {
            $host = $this->repository->find($host);
        }

        $driver = $host->driver ?? 'mysql';

        $config = match ($driver) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => $host->host,
                'port' => $host->port,
                'database' => $database ?? 'postgres',
                'username' => $host->username,
                'password' => $this->encrypter->decrypt($host->password),
                'charset' => 'utf8',
                'sslmode' => 'prefer',
                'search_path' => 'public',
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => $host->host,
                'port' => $host->port,
                'database' => $database ?? 'mysql',
                'username' => $host->username,
                'password' => $this->encrypter->decrypt($host->password),
                'charset' => 'utf8',
                'collation' => 'utf8_unicode_ci',
            ],
            default => throw new \InvalidArgumentException("Unsupported database driver: {$driver}"),
        };

        $this->config->set('database.connections.' . $connection, $config);
    }
}
