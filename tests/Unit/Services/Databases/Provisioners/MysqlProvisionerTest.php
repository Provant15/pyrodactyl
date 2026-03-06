<?php

namespace Pterodactyl\Tests\Unit\Services\Databases\Provisioners;

use Mockery;
use Mockery\MockInterface;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Tests\TestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Connection;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Services\Databases\Provisioners\MysqlProvisioner;

/**
 * Verifies that MysqlProvisioner generates correct MySQL SQL statements
 * for each provisioning operation and routes them through the dynamic
 * database connection.
 */
class MysqlProvisionerTest extends TestCase
{
    private MockInterface $databaseManager;
    private MockInterface $dynamic;
    private MockInterface $encrypter;
    private MockInterface $connection;
    private MysqlProvisioner $provisioner;

    public function setUp(): void
    {
        parent::setUp();

        $this->databaseManager = Mockery::mock(DatabaseManager::class);
        $this->dynamic = Mockery::mock(DynamicDatabaseConnection::class);
        $this->encrypter = Mockery::mock(Encrypter::class);
        $this->connection = Mockery::mock(Connection::class);

        $this->provisioner = new MysqlProvisioner(
            $this->databaseManager,
            $this->dynamic,
            $this->encrypter,
        );
    }

    /**
     * Verify that createDatabase issues a CREATE DATABASE IF NOT EXISTS statement.
     */
    public function testCreateDatabase(): void
    {
        $database = $this->makeDatabase(['database' => 's1_testdb']);
        $host = $this->makeHost();

        $this->expectDynamicSet($host);
        $this->expectStatement('CREATE DATABASE IF NOT EXISTS `s1_testdb`');

        $this->provisioner->createDatabase($database, $host);
    }

    /**
     * Verify that createUser decrypts the password and issues a CREATE USER statement.
     */
    public function testCreateUser(): void
    {
        $database = $this->makeDatabase([
            'username' => 'u1_abcdefghij',
            'remote' => '%',
            'password' => 'encrypted_password',
            'max_connections' => null,
        ]);
        $host = $this->makeHost();

        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->with('encrypted_password')
            ->andReturn('plain_password');

        $this->expectDynamicSet($host);
        $this->expectStatement("CREATE USER `u1_abcdefghij`@`%` IDENTIFIED BY 'plain_password'");

        $this->provisioner->createUser($database, $host);
    }

    /**
     * Verify that createUser appends MAX_USER_CONNECTIONS when a limit is set.
     */
    public function testCreateUserWithMaxConnections(): void
    {
        $database = $this->makeDatabase([
            'username' => 'u1_abcdefghij',
            'remote' => '%',
            'password' => 'encrypted_password',
            'max_connections' => 10,
        ]);
        $host = $this->makeHost();

        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->with('encrypted_password')
            ->andReturn('plain_password');

        $this->expectDynamicSet($host);
        $this->expectStatement("CREATE USER `u1_abcdefghij`@`%` IDENTIFIED BY 'plain_password' WITH MAX_USER_CONNECTIONS 10");

        $this->provisioner->createUser($database, $host);
    }

    /**
     * Verify that assignUserToDatabase issues a GRANT and FLUSH PRIVILEGES.
     */
    public function testAssignUserToDatabase(): void
    {
        $database = $this->makeDatabase([
            'database' => 's1_testdb',
            'username' => 'u1_abcdefghij',
            'remote' => '%',
        ]);
        $host = $this->makeHost();

        $expectedGrant = 'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, '
            . 'REFERENCES, INDEX, LOCK TABLES, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, '
            . 'CREATE TEMPORARY TABLES, CREATE VIEW, SHOW VIEW, EVENT, TRIGGER'
            . ' ON `s1_testdb`.* TO `u1_abcdefghij`@`%`';

        $this->expectDynamicSet($host, 2);
        $this->connection->shouldReceive('statement')
            ->once()
            ->with($expectedGrant)
            ->andReturn(true);
        $this->connection->shouldReceive('statement')
            ->once()
            ->with('FLUSH PRIVILEGES')
            ->andReturn(true);

        $this->databaseManager->shouldReceive('connection')
            ->with('dynamic')
            ->times(2)
            ->andReturn($this->connection);

        $this->provisioner->assignUserToDatabase($database, $host);
    }

    /**
     * Verify that dropDatabase issues a DROP DATABASE IF EXISTS statement.
     */
    public function testDropDatabase(): void
    {
        $database = $this->makeDatabase(['database' => 's1_testdb']);
        $host = $this->makeHost();

        $this->expectDynamicSet($host);
        $this->expectStatement('DROP DATABASE IF EXISTS `s1_testdb`');

        $this->provisioner->dropDatabase($database, $host);
    }

    /**
     * Verify that dropUser issues a DROP USER IF EXISTS statement.
     */
    public function testDropUser(): void
    {
        $database = $this->makeDatabase([
            'username' => 'u1_abcdefghij',
            'remote' => '%',
        ]);
        $host = $this->makeHost();

        $this->expectDynamicSet($host);
        $this->expectStatement('DROP USER IF EXISTS `u1_abcdefghij`@`%`');

        $this->provisioner->dropUser($database, $host);
    }

    /**
     * Verify that rotatePassword drops the user, recreates with the new password,
     * grants permissions, and flushes privileges.
     */
    public function testRotatePassword(): void
    {
        $database = $this->makeDatabase([
            'database' => 's1_testdb',
            'username' => 'u1_abcdefghij',
            'remote' => '%',
            'password' => 'old_encrypted',
            'max_connections' => null,
        ]);
        $host = $this->makeHost();

        // rotatePassword: dropUser -> createUser (with new encrypted pw) -> assignUserToDatabase (grant + flush)
        // Total dynamic->set calls: 4 (drop, create, grant, flush)
        $this->dynamic->shouldReceive('set')
            ->with('dynamic', $host)
            ->times(4);

        // dropUser statement
        $this->connection->shouldReceive('statement')
            ->once()
            ->with('DROP USER IF EXISTS `u1_abcdefghij`@`%`')
            ->andReturn(true);

        // encrypter->encrypt for the temp database in rotatePassword
        $this->encrypter->shouldReceive('encrypt')
            ->once()
            ->with('new_plain_password')
            ->andReturn('new_encrypted');

        // encrypter->decrypt for createUser
        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->with('new_encrypted')
            ->andReturn('new_plain_password');

        // createUser statement
        $this->connection->shouldReceive('statement')
            ->once()
            ->with("CREATE USER `u1_abcdefghij`@`%` IDENTIFIED BY 'new_plain_password'")
            ->andReturn(true);

        // GRANT statement
        $expectedGrant = 'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, '
            . 'REFERENCES, INDEX, LOCK TABLES, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, '
            . 'CREATE TEMPORARY TABLES, CREATE VIEW, SHOW VIEW, EVENT, TRIGGER'
            . ' ON `s1_testdb`.* TO `u1_abcdefghij`@`%`';

        $this->connection->shouldReceive('statement')
            ->once()
            ->with($expectedGrant)
            ->andReturn(true);

        // FLUSH PRIVILEGES
        $this->connection->shouldReceive('statement')
            ->once()
            ->with('FLUSH PRIVILEGES')
            ->andReturn(true);

        $this->databaseManager->shouldReceive('connection')
            ->with('dynamic')
            ->times(4)
            ->andReturn($this->connection);

        $this->provisioner->rotatePassword($database, $host, 'new_plain_password');
    }

    /**
     * Create a Database model instance populated with the given attributes.
     *
     * @param array<string, mixed> $attributes
     */
    private function makeDatabase(array $attributes = []): Database
    {
        $database = new Database();
        $database->forceFill(array_merge([
            'id' => 1,
            'server_id' => 1,
            'database_host_id' => 1,
            'database' => 's1_testdb',
            'username' => 'u1_abcdefghij',
            'remote' => '%',
            'password' => 'encrypted_password',
            'max_connections' => null,
        ], $attributes));

        return $database;
    }

    /**
     * Create a DatabaseHost model instance populated with default attributes.
     *
     * @param array<string, mixed> $attributes
     */
    private function makeHost(array $attributes = []): DatabaseHost
    {
        $host = new DatabaseHost();
        $host->forceFill(array_merge([
            'id' => 1,
            'name' => 'Test Host',
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'admin',
            'password' => 'encrypted_host_password',
            'max_databases' => 10,
        ], $attributes));

        return $host;
    }

    /**
     * Set up the expectation that DynamicDatabaseConnection::set() is called
     * for the given host.
     *
     * @param DatabaseHost $host The expected host argument
     * @param int $times The number of times the call is expected (default: 1)
     */
    private function expectDynamicSet(DatabaseHost $host, int $times = 1): void
    {
        $this->dynamic->shouldReceive('set')
            ->with('dynamic', $host)
            ->times($times);
    }

    /**
     * Set up the expectation that a single SQL statement is executed via the
     * dynamic database connection.
     *
     * @param string $statement The expected SQL statement
     */
    private function expectStatement(string $statement): void
    {
        $this->databaseManager->shouldReceive('connection')
            ->once()
            ->with('dynamic')
            ->andReturn($this->connection);

        $this->connection->shouldReceive('statement')
            ->once()
            ->with($statement)
            ->andReturn(true);
    }
}
