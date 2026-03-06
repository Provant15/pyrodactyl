<?php

namespace Pterodactyl\Services\Databases;

use Exception;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Helpers\Utilities;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Services\Databases\Provisioners\ProvisionerFactory;
use Pterodactyl\Exceptions\Repository\DuplicateDatabaseNameException;
use Pterodactyl\Exceptions\Service\Database\TooManyDatabasesException;
use Pterodactyl\Exceptions\Service\Database\DatabaseClientFeatureNotEnabledException;

class DatabaseManagementService
{
    /**
     * The regex used to validate that the database name passed through to the function is
     * in the expected format.
     *
     * @see \Pterodactyl\Services\Databases\DatabaseManagementService::generateUniqueDatabaseName()
     */
    private const MATCH_NAME_REGEX = '/^(s[\d]+_)(.*)$/';

    /**
     * Determines if the service should validate the user's ability to create an additional
     * database for this server. In almost all cases this should be true, but to keep things
     * flexible you can also set it to false and create more databases than the server is
     * allocated.
     */
    protected bool $validateDatabaseLimit = true;

    public function __construct(
        protected ConnectionInterface $connection,
        protected Encrypter $encrypter,
        protected ProvisionerFactory $provisionerFactory,
    ) {}

    /**
     * Generates a unique database name for the given server. This name should be passed through when
     * calling this handle function for this service, otherwise the database will be created with
     * whatever name is provided.
     */
    public static function generateUniqueDatabaseName(string $name, int $serverId): string
    {
        // Max of 48 characters, including the s123_ that we append to the front.
        return sprintf('s%d_%s', $serverId, substr($name, 0, 48 - strlen("s{$serverId}_")));
    }

    /**
     * Set whether this class should validate that the server has enough slots
     * left before creating the new database.
     */
    public function setValidateDatabaseLimit(bool $validate): self
    {
        $this->validateDatabaseLimit = $validate;

        return $this;
    }

    /**
     * Create a new database that is linked to a specific host.
     *
     * @throws \Throwable
     * @throws TooManyDatabasesException
     * @throws DatabaseClientFeatureNotEnabledException
     */
    public function create(Server $server, array $data): Database
    {
        if (!config('pterodactyl.client_features.databases.enabled')) {
            throw new DatabaseClientFeatureNotEnabledException();
        }

        if ($this->validateDatabaseLimit) {
            if (!$server->allowsDatabases()) {
                throw new TooManyDatabasesException();
            }

            // If the server has a limit assigned and we've already reached that limit, throw back
            // an exception and kill the process.
            if ($server->hasDatabaseLimit() && $server->databases()->count() >= $server->database_limit) {
                throw new TooManyDatabasesException();
            }
        }

        // Protect against developer mistakes...
        if (empty($data['database']) || !preg_match(self::MATCH_NAME_REGEX, $data['database'])) {
            throw new \InvalidArgumentException('The database name passed to DatabaseManagementService::handle MUST be prefixed with "s{server_id}_".');
        }

        $data = array_merge($data, [
            'server_id' => $server->id,
            'username' => sprintf('u%d_%s', $server->id, str_random(10)),
            'password' => $this->encrypter->encrypt(
                Utilities::randomStringWithSpecialCharacters(24)
            ),
        ]);

        $database = null;

        try {
            $host = DatabaseHost::findOrFail($data['database_host_id']);
            $provisioner = $this->provisionerFactory->forHost($host);

            if ($provisioner->requiresExternalTransaction()) {
                // For engines like PostgreSQL where DDL cannot run inside a
                // transaction block: save the model in a transaction, then
                // run provisioning SQL outside of it.
                $database = $this->connection->transaction(function () use ($data) {
                    return $this->createModel($data);
                });

                $provisioner->createDatabase($database, $host);
                $provisioner->createUser($database, $host);
                $provisioner->assignUserToDatabase($database, $host);
            } else {
                // For MySQL-compatible engines: wrap everything (model + SQL) in
                // a single transaction for atomicity. The &$database reference
                // ensures we can track the model for cleanup even if MySQL's
                // implicit DDL commit prevents a clean transaction rollback.
                $database = $this->connection->transaction(function () use ($data, $provisioner, $host, &$database) {
                    $database = $this->createModel($data);
                    $provisioner->createDatabase($database, $host);
                    $provisioner->createUser($database, $host);
                    $provisioner->assignUserToDatabase($database, $host);

                    return $database;
                });
            }

            return $database;
        } catch (\Exception $exception) {
            try {
                if ($database instanceof Database) {
                    $host = DatabaseHost::findOrFail($database->database_host_id);
                    $provisioner = $this->provisionerFactory->forHost($host);
                    $provisioner->dropDatabase($database, $host);
                    $provisioner->dropUser($database, $host);
                    $database->delete();
                }
            } catch (\Exception $deletionException) {
                // Swallow cleanup errors; original exception takes priority.
            }

            throw $exception;
        }
    }

    /**
     * Delete a database from the given host server.
     *
     * @throws \Exception
     */
    public function delete(Database $database): ?bool
    {
        $host = DatabaseHost::findOrFail($database->database_host_id);
        $provisioner = $this->provisionerFactory->forHost($host);

        $provisioner->dropDatabase($database, $host);
        $provisioner->dropUser($database, $host);

        return $database->delete();
    }

    /**
     * Create the database if there is not an identical match in the DB. While you can technically
     * have the same name across multiple hosts, for the sake of keeping this logic easy to understand
     * and avoiding user confusion we will ignore the specific host and just look across all hosts.
     *
     * @throws DuplicateDatabaseNameException
     * @throws \Throwable
     */
    protected function createModel(array $data): Database
    {
        $exists = Database::query()->where('server_id', $data['server_id'])
            ->where('database', $data['database'])
            ->exists();

        if ($exists) {
            throw new DuplicateDatabaseNameException('A database with that name already exists for this server.');
        }

        $database = (new Database())->forceFill($data);
        $database->saveOrFail();

        return $database;
    }
}
