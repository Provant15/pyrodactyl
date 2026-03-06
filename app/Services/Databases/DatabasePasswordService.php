<?php

namespace Pterodactyl\Services\Databases;

use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Helpers\Utilities;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Services\Databases\Provisioners\ProvisionerFactory;
use Pterodactyl\Contracts\Repository\DatabaseRepositoryInterface;

class DatabasePasswordService
{
    /**
     * DatabasePasswordService constructor.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private Encrypter $encrypter,
        private ProvisionerFactory $provisionerFactory,
        private DatabaseRepositoryInterface $repository,
    ) {
    }

    /**
     * Updates a password for a given database.
     *
     * @throws \Throwable
     */
    public function handle(Database|int $database): string
    {
        $password = Utilities::randomStringWithSpecialCharacters(24);

        $this->connection->transaction(function () use ($database, $password) {
            $this->repository->withoutFreshModel()->update($database->id, [
                'password' => $this->encrypter->encrypt($password),
            ]);

            $host = DatabaseHost::findOrFail($database->database_host_id);
            $provisioner = $this->provisionerFactory->forHost($host);
            $provisioner->rotatePassword($database, $host, $password);
        });

        return $password;
    }
}
