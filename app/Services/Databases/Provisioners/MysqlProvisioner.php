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
 * Handles MySQL-specific database provisioning operations.
 *
 * Executes raw SQL statements against remote MySQL hosts to create/drop
 * databases, manage users, and control permissions. Uses the dynamic
 * database connection system to establish connections to remote hosts
 * at runtime.
 */
class MysqlProvisioner implements DatabaseProvisionerInterface
{
    /**
     * The set of grants given to database users for their assigned databases.
     */
    private const USER_GRANTS = 'SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, '
        . 'REFERENCES, INDEX, LOCK TABLES, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, '
        . 'CREATE TEMPORARY TABLES, CREATE VIEW, SHOW VIEW, EVENT, TRIGGER';

    public function __construct(
        private DatabaseManager $databaseManager,
        private DynamicDatabaseConnection $dynamic,
        private Encrypter $encrypter,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function createDatabase(Database $database, DatabaseHost $host): void
    {
        $this->runOnHost($host, sprintf(
            'CREATE DATABASE IF NOT EXISTS %s',
            $this->quoteIdentifier($database->database)
        ));
    }

    /**
     * {@inheritDoc}
     *
     * Decrypts the password stored on the Database model and creates a MySQL
     * user with an optional MAX_USER_CONNECTIONS limit.
     */
    public function createUser(Database $database, DatabaseHost $host): void
    {
        $password = $this->encrypter->decrypt($database->password);
        $remote = $database->remote ?? '%';

        $command = sprintf(
            'CREATE USER %s@%s IDENTIFIED BY \'%s\'',
            $this->quoteIdentifier($database->username),
            $this->quoteIdentifier($remote),
            $this->escapeLiteral($password)
        );

        if (!empty($database->max_connections)) {
            $command .= sprintf(' WITH MAX_USER_CONNECTIONS %d', (int) $database->max_connections);
        }

        $this->runOnHost($host, $command);
    }

    /**
     * {@inheritDoc}
     *
     * Grants a standard set of privileges on the database and flushes the
     * MySQL privilege tables to ensure changes take effect immediately.
     */
    public function assignUserToDatabase(Database $database, DatabaseHost $host): void
    {
        $remote = $database->remote ?? '%';

        $this->runOnHost($host, sprintf(
            'GRANT %s ON %s.* TO %s@%s',
            self::USER_GRANTS,
            $this->quoteIdentifier($database->database),
            $this->quoteIdentifier($database->username),
            $this->quoteIdentifier($remote)
        ));

        $this->runOnHost($host, 'FLUSH PRIVILEGES');
    }

    /**
     * {@inheritDoc}
     */
    public function dropDatabase(Database $database, DatabaseHost $host): void
    {
        $this->runOnHost($host, sprintf(
            'DROP DATABASE IF EXISTS %s',
            $this->quoteIdentifier($database->database)
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function dropUser(Database $database, DatabaseHost $host): void
    {
        $remote = $database->remote ?? '%';

        $this->runOnHost($host, sprintf(
            'DROP USER IF EXISTS %s@%s',
            $this->quoteIdentifier($database->username),
            $this->quoteIdentifier($remote)
        ));
    }

    /**
     * {@inheritDoc}
     *
     * Rotates a MySQL user's password by dropping and recreating the user,
     * then re-granting permissions. This approach ensures a clean state
     * regardless of the MySQL version's ALTER USER support.
     */
    public function rotatePassword(Database $database, DatabaseHost $host, string $newPassword): void
    {
        $this->dropUser($database, $host);

        // Build a temporary Database model with the new encrypted password
        // so createUser() can decrypt it via the standard path.
        $tempDatabase = clone $database;
        $tempDatabase->forceFill([
            'password' => $this->encrypter->encrypt($newPassword),
        ]);

        $this->createUser($tempDatabase, $host);
        $this->assignUserToDatabase($database, $host);
    }

    /**
     * {@inheritDoc}
     *
     * Opens a raw PDO connection to the MySQL host using the admin credentials,
     * then checks the server version and verifies that the admin user holds
     * sufficient privileges (GRANT OPTION) to manage databases.
     */
    public function testConnection(DatabaseHost $host): array
    {
        $password = $this->encrypter->decrypt($host->password);
        $dsn = sprintf('mysql:host=%s;port=%d', $host->host, $host->port);

        try {
            $pdo = new PDO($dsn, $host->username, $password, [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);

            $hasGrant = false;
            foreach ($grants as $grant) {
                if (stripos($grant, 'GRANT OPTION') !== false || stripos($grant, 'ALL PRIVILEGES') !== false) {
                    $hasGrant = true;
                    break;
                }
            }

            return [
                'version' => $version ?: 'unknown',
                'has_required_permissions' => $hasGrant,
                'message' => $hasGrant
                    ? 'Connection successful with required permissions.'
                    : 'Connection successful but the user lacks GRANT OPTION privilege.',
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
     * MySQL supports DDL inside transactions (with implicit commits).
     */
    public function requiresExternalTransaction(): bool
    {
        return false;
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
     * Quote a MySQL identifier (database name, username, host) with backticks,
     * escaping any embedded backticks by doubling them.
     */
    private function quoteIdentifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * Escape a string value for use inside a single-quoted SQL literal,
     * handling backslashes and single quotes.
     */
    private function escapeLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
