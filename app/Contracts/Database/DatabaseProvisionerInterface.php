<?php

namespace Pterodactyl\Contracts\Database;

use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;

/**
 * Defines the contract for database provisioning operations.
 *
 * Each database engine (MySQL, PostgreSQL) implements this interface
 * to handle engine-specific SQL, connection semantics, and permission models.
 * Methods accept Eloquent model context objects so each implementation can
 * read whatever fields it needs (e.g. MySQL reads $database->remote;
 * PostgreSQL ignores it).
 */
interface DatabaseProvisionerInterface
{
    /**
     * Create a new database on the remote host.
     *
     * @param Database $database The database model (contains name, username, etc.)
     * @param DatabaseHost $host The host to create the database on
     *
     * @throws \Exception If creation fails
     */
    public function createDatabase(Database $database, DatabaseHost $host): void;

    /**
     * Create a new user/role on the remote host.
     *
     * @param Database $database The database model (contains username, password, remote, max_connections)
     * @param DatabaseHost $host The host to create the user on
     *
     * @throws \Exception If user creation fails
     */
    public function createUser(Database $database, DatabaseHost $host): void;

    /**
     * Grant the database user access to its database.
     *
     * @param Database $database The database model (contains database name, username, remote)
     * @param DatabaseHost $host The host where grants are applied
     *
     * @throws \Exception If grant fails
     */
    public function assignUserToDatabase(Database $database, DatabaseHost $host): void;

    /**
     * Drop a database from the remote host.
     *
     * @param Database $database The database model
     * @param DatabaseHost $host The host to drop the database from
     *
     * @throws \Exception If drop fails
     */
    public function dropDatabase(Database $database, DatabaseHost $host): void;

    /**
     * Drop a user/role from the remote host.
     *
     * @param Database $database The database model (contains username, remote)
     * @param DatabaseHost $host The host to drop the user from
     *
     * @throws \Exception If drop fails
     */
    public function dropUser(Database $database, DatabaseHost $host): void;

    /**
     * Rotate the password for an existing database user.
     *
     * @param Database $database The database model (contains username, remote)
     * @param DatabaseHost $host The host where the user exists
     * @param string $newPassword The new plaintext password
     *
     * @throws \Exception If rotation fails
     */
    public function rotatePassword(Database $database, DatabaseHost $host, string $newPassword): void;

    /**
     * Test connectivity and verify the admin user has sufficient privileges
     * to provision databases on this host.
     *
     * @param DatabaseHost $host The host to test
     *
     * @return array{version: string, has_required_permissions: bool, message: string}
     *
     * @throws \Exception If connection fails
     */
    public function testConnection(DatabaseHost $host): array;

    // Future: shared database support (see Appendix A of postgres-db-hosts-plan.md)
    // public function grantAccess(Database $database, DatabaseHost $host, string $username, string $password): void;
    // public function revokeAccess(Database $database, DatabaseHost $host, string $username): void;
}
