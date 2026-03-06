<?php

namespace Pterodactyl\Services\Databases\Hosts;

use Pterodactyl\Models\DatabaseHost;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Services\Databases\Provisioners\ProvisionerFactory;
use Pterodactyl\Contracts\Repository\DatabaseHostRepositoryInterface;

class HostUpdateService
{
    /**
     * HostUpdateService constructor.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private Encrypter $encrypter,
        private ProvisionerFactory $provisionerFactory,
        private DatabaseHostRepositoryInterface $repository,
    ) {
    }

    /**
     * Update a database host and persist to the database.
     *
     * @throws \Throwable
     */
    public function handle(int $hostId, array $data): DatabaseHost
    {
        // Prevent changing the driver of a host that already has databases provisioned,
        // as existing databases would become unmanageable under a different driver.
        $existing = DatabaseHost::findOrFail($hostId);
        if (isset($data['driver']) && $data['driver'] !== ($existing->driver ?? 'mysql') && $existing->databases()->count() > 0) {
            throw new \LogicException('Cannot change the driver of a database host that has active databases.');
        }

        if (!empty(array_get($data, 'password'))) {
            $data['password'] = $this->encrypter->encrypt($data['password']);
        } else {
            unset($data['password']);
        }

        return $this->connection->transaction(function () use ($data, $hostId) {
            $host = $this->repository->update($hostId, $data);

            // Confirm access using the provided/updated credentials before saving data.
            $provisioner = $this->provisionerFactory->forHost($host);
            $result = $provisioner->testConnection($host);

            if (!$result['has_required_permissions']) {
                throw new \RuntimeException($result['message']);
            }

            return $host;
        });
    }
}
