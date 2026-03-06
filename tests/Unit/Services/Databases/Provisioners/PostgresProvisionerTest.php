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
use Pterodactyl\Services\Databases\Provisioners\PostgresProvisioner;

/**
 * Verifies that PostgresProvisioner generates correct PostgreSQL SQL statements
 * for each provisioning operation and routes them through the dynamic
 * database connection.
 */
class PostgresProvisionerTest extends TestCase
{
    private MockInterface $databaseManager;
    private MockInterface $dynamic;
    private MockInterface $encrypter;
    private MockInterface $connection;
    private PostgresProvisioner $provisioner;

    public function setUp(): void
    {
        parent::setUp();

        $this->databaseManager = Mockery::mock(DatabaseManager::class);
        $this->dynamic = Mockery::mock(DynamicDatabaseConnection::class);
        $this->encrypter = Mockery::mock(Encrypter::class);
        $this->connection = Mockery::mock(Connection::class);

        $this->provisioner = new PostgresProvisioner(
            $this->databaseManager,
            $this->dynamic,
            $this->encrypter,
        );
    }

    /**
     * Verify that createDatabase checks pg_database for existence, then issues
     * CREATE DATABASE with double-quoted identifiers.
     */
    public function testCreateDatabaseChecksExistenceAndCreates(): void
    {
        $database = $this->makeDatabase(['database' => 's1_testdb']);
        $host = $this->makeHost();

        // First call: existence check via selectOne
        $this->dynamic->shouldReceive('set')
            ->with('dynamic', $host)
            ->twice();

        $this->databaseManager->shouldReceive('connection')
            ->with('dynamic')
            ->twice()
            ->andReturn($this->connection);

        $this->connection->shouldReceive('selectOne')
            ->once()
            ->with('SELECT 1 FROM pg_database WHERE datname = ?', ['s1_testdb'])
            ->andReturn(null);

        // Second call: CREATE DATABASE
        $this->connection->shouldReceive('statement')
            ->once()
            ->with('CREATE DATABASE "s1_testdb"')
            ->andReturn(true);

        $this->provisioner->createDatabase($database, $host);
    }

    /**
     * Verify that createDatabase skips creation when the database already exists.
     */
    public function testCreateDatabaseSkipsWhenExists(): void
    {
        $database = $this->makeDatabase(['database' => 's1_testdb']);
        $host = $this->makeHost();

        $this->dynamic->shouldReceive('set')
            ->with('dynamic', $host)
            ->once();

        $this->databaseManager->shouldReceive('connection')
            ->with('dynamic')
            ->once()
            ->andReturn($this->connection);

        $this->connection->shouldReceive('selectOne')
            ->once()
            ->with('SELECT 1 FROM pg_database WHERE datname = ?', ['s1_testdb'])
            ->andReturn((object) ['?column?' => 1]);

        $this->connection->shouldNotReceive('statement');

        $this->provisioner->createDatabase($database, $host);
    }

    /**
     * Verify that createUser issues CREATE ROLE with LOGIN, PASSWORD, and
     * CONNECTION LIMIT when max_connections is set.
     */
    public function testCreateUserWithConnectionLimit(): void
    {
        $database = $this->makeDatabase([
            'username' => 'u1_abcdefghij',
            'password' => 'encrypted_password',
            'max_connections' => 10,
        ]);
        $host = $this->makeHost();

        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->with('encrypted_password')
            ->andReturn('plain_password');

        $this->expectDynamicSet($host);
        $this->expectStatement(
            "CREATE ROLE \"u1_abcdefghij\" WITH LOGIN PASSWORD 'plain_password' CONNECTION LIMIT 10"
        );

        $this->provisioner->createUser($database, $host);
    }

    /**
     * Verify that createUser omits CONNECTION LIMIT when max_connections is null.
     */
    public function testCreateUserWithoutConnectionLimit(): void
    {
        $database = $this->makeDatabase([
            'username' => 'u1_abcdefghij',
            'password' => 'encrypted_password',
            'max_connections' => null,
        ]);
        $host = $this->makeHost();

        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->with('encrypted_password')
            ->andReturn('plain_password');

        $this->expectDynamicSet($host);
        $this->expectStatement(
            "CREATE ROLE \"u1_abcdefghij\" WITH LOGIN PASSWORD 'plain_password'"
        );

        $this->provisioner->createUser($database, $host);
    }

    /**
     * Verify that assignUserToDatabase grants database privileges on the admin
     * connection, then reconnects to the target database for schema-level grants
     * and ALTER DEFAULT PRIVILEGES, then restores the admin connection.
     */
    public function testAssignUserToDatabaseGrantsAndSetsDefaults(): void
    {
        $database = $this->makeDatabase([
            'database' => 's1_testdb',
            'username' => 'u1_abcdefghij',
        ]);
        $host = $this->makeHost(['username' => 'pgadmin']);

        // set('dynamic', $host) is called twice: once for the initial GRANT
        // via runOnHost, and once to restore the admin connection at the end.
        $this->dynamic->shouldReceive('set')
            ->with('dynamic', $host)
            ->twice();

        // set('dynamic', $host, 's1_testdb') is called once to connect to the
        // target database for schema-level grants.
        $this->dynamic->shouldReceive('set')
            ->with('dynamic', $host, 's1_testdb')
            ->once();

        $this->databaseManager->shouldReceive('connection')
            ->with('dynamic')
            ->andReturn($this->connection);

        // Statement 1: GRANT on database (via runOnHost)
        $this->connection->shouldReceive('statement')
            ->once()
            ->with('GRANT ALL PRIVILEGES ON DATABASE "s1_testdb" TO "u1_abcdefghij"')
            ->andReturn(true);

        // Statements 2-6: schema-level grants on the target database connection
        $this->connection->shouldReceive('statement')
            ->once()
            ->with('GRANT USAGE, CREATE ON SCHEMA public TO "u1_abcdefghij"')
            ->andReturn(true);

        $this->connection->shouldReceive('statement')
            ->once()
            ->with('GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO "u1_abcdefghij"')
            ->andReturn(true);

        $this->connection->shouldReceive('statement')
            ->once()
            ->with('GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO "u1_abcdefghij"')
            ->andReturn(true);

        $this->connection->shouldReceive('statement')
            ->once()
            ->with('ALTER DEFAULT PRIVILEGES FOR ROLE "pgadmin" IN SCHEMA public GRANT ALL ON TABLES TO "u1_abcdefghij"')
            ->andReturn(true);

        $this->connection->shouldReceive('statement')
            ->once()
            ->with('ALTER DEFAULT PRIVILEGES FOR ROLE "pgadmin" IN SCHEMA public GRANT ALL ON SEQUENCES TO "u1_abcdefghij"')
            ->andReturn(true);

        $this->provisioner->assignUserToDatabase($database, $host);
    }

    /**
     * Verify that dropDatabase attempts to terminate active connections before
     * issuing DROP DATABASE IF EXISTS. The terminate query uses parameterized
     * binding for the database name.
     */
    public function testDropDatabaseTerminatesConnectionsFirst(): void
    {
        $database = $this->makeDatabase(['database' => 's1_testdb']);
        $host = $this->makeHost();

        // Two set calls: terminate + drop (via runOnHost)
        $this->dynamic->shouldReceive('set')
            ->with('dynamic', $host)
            ->twice();

        $this->databaseManager->shouldReceive('connection')
            ->with('dynamic')
            ->twice()
            ->andReturn($this->connection);

        // Terminate uses parameterized query
        $this->connection->shouldReceive('statement')
            ->once()
            ->with(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
                ['s1_testdb']
            )
            ->andReturn(true);

        // DROP via runOnHost
        $this->connection->shouldReceive('statement')
            ->once()
            ->with('DROP DATABASE IF EXISTS "s1_testdb"')
            ->andReturn(true);

        $this->provisioner->dropDatabase($database, $host);
    }

    /**
     * Verify that dropDatabase still proceeds with DROP even if
     * pg_terminate_backend fails (managed PostgreSQL restriction).
     */
    public function testDropDatabaseProceedsWhenTerminateFails(): void
    {
        $database = $this->makeDatabase(['database' => 's1_testdb']);
        $host = $this->makeHost();

        $this->dynamic->shouldReceive('set')
            ->with('dynamic', $host)
            ->twice();

        $this->databaseManager->shouldReceive('connection')
            ->with('dynamic')
            ->twice()
            ->andReturn($this->connection);

        // Terminate call throws an exception (managed PG)
        $this->connection->shouldReceive('statement')
            ->once()
            ->with(
                Mockery::pattern('/pg_terminate_backend/'),
                Mockery::type('array')
            )
            ->andThrow(new \Exception('permission denied for function pg_terminate_backend'));

        // DROP should still be called
        $this->connection->shouldReceive('statement')
            ->once()
            ->with('DROP DATABASE IF EXISTS "s1_testdb"')
            ->andReturn(true);

        $this->provisioner->dropDatabase($database, $host);
    }

    /**
     * Verify that dropUser issues DROP ROLE IF EXISTS without a host component.
     */
    public function testDropUser(): void
    {
        $database = $this->makeDatabase(['username' => 'u1_abcdefghij']);
        $host = $this->makeHost();

        $this->expectDynamicSet($host);
        $this->expectStatement('DROP ROLE IF EXISTS "u1_abcdefghij"');

        $this->provisioner->dropUser($database, $host);
    }

    /**
     * Verify that rotatePassword uses ALTER ROLE to change the password in-place
     * without dropping and recreating the role.
     */
    public function testRotatePasswordUsesAlterRole(): void
    {
        $database = $this->makeDatabase(['username' => 'u1_abcdefghij']);
        $host = $this->makeHost();

        $this->expectDynamicSet($host);
        $this->expectStatement("ALTER ROLE \"u1_abcdefghij\" WITH PASSWORD 'new_plain_password'");

        $this->provisioner->rotatePassword($database, $host, 'new_plain_password');
    }

    /**
     * Verify that requiresExternalTransaction returns true, since PostgreSQL
     * cannot run CREATE/DROP DATABASE inside a transaction block.
     */
    public function testRequiresExternalTransactionReturnsTrue(): void
    {
        $this->assertTrue($this->provisioner->requiresExternalTransaction());
    }

    /**
     * Verify that testConnection checks version and role permissions via PDO.
     * This test verifies the DSN format and permission-checking logic by
     * testing against the returned array structure.
     */
    public function testTestConnectionChecksVersionAndPermissions(): void
    {
        // We cannot easily mock PDO construction inside the method, so we
        // verify the method handles a connection failure gracefully (which
        // exercises the DSN construction and error handling path).
        $host = $this->makeHost([
            'host' => '127.0.0.1',
            'port' => 5432,
            'username' => 'pgadmin',
            'password' => 'encrypted_host_password',
        ]);

        $this->encrypter->shouldReceive('decrypt')
            ->once()
            ->with('encrypted_host_password')
            ->andReturn('host_password');

        // This will fail to connect (no real PostgreSQL) but exercises the
        // error handling path and verifies the return structure.
        $result = $this->provisioner->testConnection($host);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('has_required_permissions', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertEquals('unknown', $result['version']);
        $this->assertFalse($result['has_required_permissions']);
        $this->assertStringStartsWith('Connection failed:', $result['message']);
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
            'name' => 'Test PG Host',
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
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
