<?php

namespace Pterodactyl\Contracts\Repository;

use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Repository interface for Database model Eloquent queries.
 *
 * Provisioning SQL (CREATE/DROP/GRANT) is handled by DatabaseProvisionerInterface.
 * This repository handles only Eloquent queries against the panel's own database.
 */
interface DatabaseRepositoryInterface extends RepositoryInterface
{
    public const DEFAULT_CONNECTION_NAME = 'dynamic';

    /**
     * Set the connection name to execute statements against.
     */
    public function setConnection(string $connection): self;

    /**
     * Return the connection to execute statements against.
     */
    public function getConnection(): string;

    /**
     * Return all the databases belonging to a server.
     */
    public function getDatabasesForServer(int $server): Collection;

    /**
     * Return all the databases for a given host with the server relationship loaded.
     */
    public function getDatabasesForHost(int $host, int $count = 25): LengthAwarePaginator;
}
