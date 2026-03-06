<?php

namespace Pterodactyl\Services\Databases\Hosts;

use Pterodactyl\Models\DatabaseHost;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Services\Databases\Provisioners\ProvisionerFactory;
use Pterodactyl\Contracts\Repository\DatabaseHostRepositoryInterface;

class HostCreationService
{
    /**
     * HostCreationService constructor.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private Encrypter $encrypter,
        private ProvisionerFactory $provisionerFactory,
        private DatabaseHostRepositoryInterface $repository,
    ) {
    }

    /**
     * Create a new database host on the Panel.
     *
     * @throws \Throwable
     */
    public function handle(array $data): DatabaseHost
    {
        return $this->connection->transaction(function () use ($data) {
            $host = $this->repository->create([
                'password' => $this->encrypter->encrypt(array_get($data, 'password')),
                'name' => array_get($data, 'name'),
                'host' => array_get($data, 'host'),
                'port' => array_get($data, 'port'),
                'username' => array_get($data, 'username'),
                'max_databases' => null,
                'node_id' => array_get($data, 'node_id'),
                'driver' => array_get($data, 'driver', 'mysql'),
            ]);

            // Confirm access using the provided credentials before saving data.
            $provisioner = $this->provisionerFactory->forHost($host);
            $result = $provisioner->testConnection($host);

            if (!$result['has_required_permissions']) {
                throw new \RuntimeException($result['message']);
            }

            return $host;
        });
    }
}
