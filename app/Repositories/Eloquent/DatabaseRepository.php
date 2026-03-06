<?php

namespace Pterodactyl\Repositories\Eloquent;

use Pterodactyl\Models\Database;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Pterodactyl\Contracts\Repository\DatabaseRepositoryInterface;

/**
 * Eloquent repository for the Database model.
 *
 * Handles Eloquent queries against the panel's own database (listing, pagination).
 * Provisioning SQL (CREATE/DROP/GRANT) is handled by DatabaseProvisionerInterface
 * implementations via the ProvisionerFactory.
 */
class DatabaseRepository extends EloquentRepository implements DatabaseRepositoryInterface
{
    protected string $connection = self::DEFAULT_CONNECTION_NAME;

    /**
     * Return the model backing this repository.
     */
    public function model(): string
    {
        return Database::class;
    }

    /**
     * Return the connection to execute statements against.
     */
    public function getConnection(): string
    {
        return $this->connection;
    }

    /**
     * Set the connection name to execute statements against.
     */
    public function setConnection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Return all the databases belonging to a server.
     */
    public function getDatabasesForServer(int $server): Collection
    {
        return $this->getBuilder()->with('host')->where('server_id', $server)->get($this->getColumns());
    }

    /**
     * Return all the databases for a given host with the server relationship loaded.
     */
    public function getDatabasesForHost(int $host, int $count = 25): LengthAwarePaginator
    {
        return $this->getBuilder()->with('server')
            ->where('database_host_id', $host)
            ->paginate($count, $this->getColumns());
    }
}
