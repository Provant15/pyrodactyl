<?php

namespace Pterodactyl\Services\Databases;

use Webmozart\Assert\Assert;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Exceptions\Service\Database\NoSuitableDatabaseHostException;

class DeployServerDatabaseService
{
    /**
     * DeployServerDatabaseService constructor.
     */
    public function __construct(private DatabaseManagementService $managementService)
    {
    }

    /**
     * Deploy a database for the given server, selecting a suitable host.
     *
     * Picks a database host on the same node when available, otherwise falls
     * back to a random host if the configuration allows it. The `remote` field
     * defaults to '%' (allow all) when not supplied - PostgreSQL hosts do not
     * use user@host grants, so requiring it would be incorrect.
     *
     * @param Server $server The server to create a database for.
     * @param array  $data   Must contain 'database'; 'remote' is optional.
     *
     * @throws \Pterodactyl\Exceptions\Service\Database\NoSuitableDatabaseHostException
     * @throws \Pterodactyl\Exceptions\Service\Database\TooManyDatabasesException
     * @throws \Pterodactyl\Exceptions\Service\Database\DatabaseClientFeatureNotEnabledException
     * @throws \Throwable
     */
    public function handle(Server $server, array $data): Database
    {
        Assert::notEmpty($data['database'] ?? null);

        $hosts = DatabaseHost::query()->get()->toBase();
        if ($hosts->isEmpty()) {
            throw new NoSuitableDatabaseHostException();
        } else {
            $nodeHosts = $hosts->where('node_id', $server->node_id)->toBase();

            if ($nodeHosts->isEmpty() && !config('pterodactyl.client_features.databases.allow_random')) {
                throw new NoSuitableDatabaseHostException();
            }
        }

        return $this->managementService->create($server, [
            'database_host_id' => $nodeHosts->isEmpty()
                ? $hosts->random()->id
                : $nodeHosts->random()->id,
            'database' => DatabaseManagementService::generateUniqueDatabaseName($data['database'], $server->id),
            'remote' => $data['remote'] ?? '%',
        ]);
    }
}
