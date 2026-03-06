<?php

namespace Pterodactyl\Services\Databases\Provisioners;

use PDO;
use PDOException;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Contracts\Database\DatabaseProvisionerInterface;

/**
 * Handles PostgreSQL-specific database provisioning operations.
 *
 * Executes raw SQL statements against remote PostgreSQL hosts to create/drop
 * databases, manage roles, and control permissions. Uses the dynamic database
 * connection system to establish connections to remote hosts at runtime.
 *
 * Key differences from MySQL provisioning:
 * - Uses double-quoted identifiers instead of backticks
 * - No host-scoped users (PostgreSQL roles are cluster-wide)
 * - CREATE/DROP DATABASE cannot run inside a transaction block
 * - Schema-level grants are required for table/sequence access
 * - ALTER DEFAULT PRIVILEGES ensures future objects inherit grants
 */
class PostgresProvisioner implements DatabaseProvisionerInterface
{
    public function __construct(
        private DatabaseManager $databaseManager,
        private DynamicDatabaseConnection $dynamic,
        private Encrypter $encrypter,
    ) {}

    /**
     * {@inheritDoc}
     *
     * Checks pg_database for an existing database before issuing CREATE DATABASE.
     * PostgreSQL does not support IF NOT EXISTS for CREATE DATABASE, so the
     * existence check prevents errors on re-runs. Catches SQLSTATE 42P04
     * (duplicate_database) as a safety net for race conditions.
     */
    public function createDatabase(Database $database, DatabaseHost $host): void
    {
        $this->dynamic->set('dynamic', $host);
        $connection = $this->databaseManager->connection('dynamic');

        $exists = $connection->selectOne(
            'SELECT 1 FROM pg_database WHERE datname = ?',
            [$database->database]
        );

        if ($exists) {
            return;
        }

        try {
            $this->runOnHost($host, sprintf(
                'CREATE DATABASE %s',
                $this->quoteIdentifier($database->database)
            ));
        } catch (\Exception $e) {
            // SQLSTATE 42P04: duplicate_database - safe to ignore (race condition)
            if ($this->isSqlState($e, '42P04')) {
                return;
            }
            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Creates a PostgreSQL role with LOGIN capability. Unlike MySQL, PostgreSQL
     * roles are cluster-wide and do not include a host component. Applies an
     * optional CONNECTION LIMIT if max_connections is set on the database model.
     * Catches SQLSTATE 42710 (duplicate_object) for race conditions.
     */
    public function createUser(Database $database, DatabaseHost $host): void
    {
        $password = $this->encrypter->decrypt($database->password);

        $command = sprintf(
            'CREATE ROLE %s WITH LOGIN PASSWORD \'%s\'',
            $this->quoteIdentifier($database->username),
            $this->escapeLiteral($password)
        );

        if (!empty($database->max_connections)) {
            $command .= sprintf(' CONNECTION LIMIT %d', (int) $database->max_connections);
        }

        try {
            $this->runOnHost($host, $command);
        } catch (\Exception $e) {
            // SQLSTATE 42710: duplicate_object - safe to ignore (race condition)
            if ($this->isSqlState($e, '42710')) {
                return;
            }
            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Grants access in three stages:
     * 1. GRANT ALL PRIVILEGES ON DATABASE to the role (admin connection)
     * 2. Connect to the target database and grant schema-level permissions:
     *    USAGE/CREATE on public schema, ALL on tables/sequences, plus ALTER
     *    DEFAULT PRIVILEGES so future objects created by the admin role are
     *    automatically accessible
     * 3. Reconnect to the admin database to restore the default connection state
     */
    public function assignUserToDatabase(Database $database, DatabaseHost $host): void
    {
        $dbIdent = $this->quoteIdentifier($database->database);
        $userIdent = $this->quoteIdentifier($database->username);
        $adminIdent = $this->quoteIdentifier($host->username);

        // Step 1: Grant database-level privileges on the admin connection
        $this->runOnHost($host, sprintf(
            'GRANT ALL PRIVILEGES ON DATABASE %s TO %s',
            $dbIdent,
            $userIdent
        ));

        // Step 2: Connect to the target database for schema-level grants
        $this->dynamic->set('dynamic', $host, $database->database);
        $connection = $this->databaseManager->connection('dynamic');

        $connection->statement(sprintf('GRANT USAGE, CREATE ON SCHEMA public TO %s', $userIdent));
        $connection->statement(sprintf('GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO %s', $userIdent));
        $connection->statement(sprintf('GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO %s', $userIdent));
        $connection->statement(sprintf('ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA public GRANT ALL ON TABLES TO %s', $adminIdent, $userIdent));
        $connection->statement(sprintf('ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA public GRANT ALL ON SEQUENCES TO %s', $adminIdent, $userIdent));

        // Step 3: Restore connection to the admin database for subsequent operations
        $this->dynamic->set('dynamic', $host);
    }

    /**
     * {@inheritDoc}
     *
     * Terminates active connections to the database before dropping it.
     * The pg_terminate_backend call is wrapped in a try/catch because managed
     * PostgreSQL services may restrict this function. The DROP DATABASE uses
     * IF EXISTS to be idempotent.
     */
    public function dropDatabase(Database $database, DatabaseHost $host): void
    {
        // Attempt to terminate active connections; some managed PostgreSQL
        // services may not permit pg_terminate_backend.
        try {
            $this->dynamic->set('dynamic', $host);
            $this->databaseManager->connection('dynamic')->statement(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
                [$database->database]
            );
        } catch (\Exception) {
            // Swallow termination errors - the DROP may still succeed if
            // there are no active connections.
        }

        $this->runOnHost($host, sprintf(
            'DROP DATABASE IF EXISTS %s',
            $this->quoteIdentifier($database->database)
        ));
    }

    /**
     * {@inheritDoc}
     *
     * Drops the PostgreSQL role. Unlike MySQL, there is no host component -
     * roles are cluster-wide.
     */
    public function dropUser(Database $database, DatabaseHost $host): void
    {
        $this->runOnHost($host, sprintf(
            'DROP ROLE IF EXISTS %s',
            $this->quoteIdentifier($database->username)
        ));
    }

    /**
     * {@inheritDoc}
     *
     * PostgreSQL supports ALTER ROLE to change a password in-place, without
     * needing to drop and recreate the role or re-grant permissions.
     */
    public function rotatePassword(Database $database, DatabaseHost $host, string $newPassword): void
    {
        $this->runOnHost($host, sprintf(
            'ALTER ROLE %s WITH PASSWORD \'%s\'',
            $this->quoteIdentifier($database->username),
            $this->escapeLiteral($newPassword)
        ));
    }

    /**
     * {@inheritDoc}
     *
     * Opens a raw PDO connection to the PostgreSQL host using the admin
     * credentials, then checks the server version and verifies that the admin
     * role has sufficient privileges to provision databases and roles. The admin
     * must be a superuser, or hold both CREATEROLE and CREATEDB privileges.
     */
    public function testConnection(DatabaseHost $host): array
    {
        $password = $this->encrypter->decrypt($host->password);
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres', $host->host, $host->port);

        try {
            $pdo = new PDO($dsn, $host->username, $password, [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $version = $pdo->query('SELECT version()')->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT rolsuper, rolcreaterole, rolcreatedb FROM pg_roles WHERE rolname = current_user'
            );
            $stmt->execute();
            $role = $stmt->fetch(PDO::FETCH_ASSOC);

            $hasPermissions = false;
            if ($role) {
                $isSuperuser = filter_var($role['rolsuper'], FILTER_VALIDATE_BOOLEAN);
                $canCreateRole = filter_var($role['rolcreaterole'], FILTER_VALIDATE_BOOLEAN);
                $canCreateDb = filter_var($role['rolcreatedb'], FILTER_VALIDATE_BOOLEAN);

                $hasPermissions = $isSuperuser || ($canCreateRole && $canCreateDb);
            }

            return [
                'version' => $version ?: 'unknown',
                'has_required_permissions' => $hasPermissions,
                'message' => $hasPermissions
                    ? 'Connection successful with required permissions.'
                    : 'Connection successful but the role lacks required privileges (needs superuser or CREATEROLE + CREATEDB).',
            ];
        } catch (PDOException $e) {
            return [
                'version' => 'unknown',
                'has_required_permissions' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     *
     * PostgreSQL cannot run CREATE DATABASE or DROP DATABASE inside a
     * transaction block. The calling service must save the Eloquent model
     * in a separate transaction and run provisioning SQL outside of it.
     */
    public function requiresExternalTransaction(): bool
    {
        return true;
    }

    /**
     * Configure the dynamic connection for the given host and execute a SQL statement.
     *
     * @param DatabaseHost $host The remote host to connect to
     * @param string $statement The raw SQL statement to execute
     */
    private function runOnHost(DatabaseHost $host, string $statement): void
    {
        $this->dynamic->set('dynamic', $host);
        $this->databaseManager->connection('dynamic')->statement($statement);
    }

    /**
     * Quote a PostgreSQL identifier (database name, role name) with double quotes,
     * escaping any embedded double quotes by doubling them.
     */
    private function quoteIdentifier(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * Escape a string value for use inside a single-quoted SQL literal.
     * PostgreSQL uses doubled single quotes for escaping (not backslashes).
     */
    private function escapeLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * Check if an exception corresponds to a specific PostgreSQL SQLSTATE code.
     * Uses the exception's error code rather than message substring matching
     * for reliability across locales and driver versions.
     *
     * @param \Exception $e The caught exception
     * @param string $sqlState The 5-character SQLSTATE code to check
     */
    private function isSqlState(\Exception $e, string $sqlState): bool
    {
        // PDOException and QueryException expose SQLSTATE via getCode()
        if ($e->getCode() === $sqlState) {
            return true;
        }

        // Laravel's QueryException wraps PDOException as the previous exception
        $previous = $e->getPrevious();

        return $previous instanceof \Exception && $previous->getCode() === $sqlState;
    }
}
