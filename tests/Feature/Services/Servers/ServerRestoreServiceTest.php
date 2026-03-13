<?php

namespace Pterodactyl\Tests\Feature\Services\Servers;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Models\User;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\Servers\ServerRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Pterodactyl\Tests\TestCase;

class ServerRestoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServerRestoreService $service;
    private Nest $nest;
    private Location $location;

    public function setUp(): void
    {
        parent::setUp();

        $this->nest = Nest::factory()->create();
        $this->location = Location::factory()->create();

        $this->mock(DaemonServerRepository::class, function ($mock) {
            $mock->shouldReceive('setServer')->andReturnSelf();
            $mock->shouldReceive('reinstall')->andReturn(null);
        });

        $this->service = $this->app->make(ServerRestoreService::class);
    }

    /**
     * Helper to create an archived server with all required FK dependencies.
     *
     * Archived servers have no allocation - the allocation_id is set to null
     * after creation to simulate the state left by the archive process.
     */
    private function createArchivedServer(Node $node, array $overrides = []): Server
    {
        $user = User::factory()->create();
        $egg = Egg::factory()->create([
            'nest_id' => $this->nest->id,
            'author' => 'test@example.com',
            'docker_images' => ['ghcr.io/test:latest'],
            'config_stop' => 'stop',
            'config_startup' => '{"done": "Started"}',
            'config_logs' => '{}',
            'config_files' => '{}',
        ]);
        $allocation = Allocation::factory()->create(['node_id' => $node->id, 'server_id' => null]);

        $defaults = [
            'owner_id' => $user->id,
            'node_id' => $node->id,
            'allocation_id' => $allocation->id,
            'nest_id' => $this->nest->id,
            'egg_id' => $egg->id,
            'status' => Server::STATUS_ARCHIVED,
            'archive_snapshot_id' => 'snap-abc123',
        ];

        $server = Server::factory()->create(array_merge($defaults, $overrides));

        // Null out allocation to simulate archived state (no allocation bound)
        $server->update(['allocation_id' => null]);
        $server->refresh();

        return $server;
    }

    public function test_initiates_restore_of_archived_server(): void
    {
        $node = Node::factory()->create(['location_id' => $this->location->id]);
        $slot = ServerSlot::factory()->create([
            'node_id' => $node->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);
        $server = $this->createArchivedServer($node);

        $this->service->handle($slot, $server);

        $slot->refresh();
        $this->assertEquals(ServerSlot::STATUS_RESTORING, $slot->status);
        $this->assertEquals($server->id, $slot->active_server_id);

        $server->refresh();
        $this->assertEquals(Server::STATUS_INSTALLING, $server->status);
        $this->assertEquals($slot->allocation_id, $server->allocation_id);
        $this->assertEquals($slot->id, $server->slot_id);
    }

    public function test_rejects_restore_when_server_on_different_node(): void
    {
        $node1 = Node::factory()->create(['location_id' => $this->location->id]);
        $node2 = Node::factory()->create(['location_id' => $this->location->id]);
        $slot = ServerSlot::factory()->create([
            'node_id' => $node1->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);
        $server = $this->createArchivedServer($node2);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('different node');

        $this->service->handle($slot, $server);
    }

    public function test_rejects_restore_of_non_archived_server(): void
    {
        $node = Node::factory()->create(['location_id' => $this->location->id]);
        $user = User::factory()->create();
        $egg = Egg::factory()->create([
            'nest_id' => $this->nest->id,
            'author' => 'test@example.com',
            'docker_images' => ['ghcr.io/test:latest'],
            'config_stop' => 'stop',
            'config_startup' => '{"done": "Started"}',
            'config_logs' => '{}',
            'config_files' => '{}',
        ]);
        $allocation = Allocation::factory()->create(['node_id' => $node->id, 'server_id' => null]);
        $slot = ServerSlot::factory()->create([
            'node_id' => $node->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);
        $server = Server::factory()->create([
            'owner_id' => $user->id,
            'node_id' => $node->id,
            'allocation_id' => $allocation->id,
            'nest_id' => $this->nest->id,
            'egg_id' => $egg->id,
            'status' => null,
        ]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->service->handle($slot, $server);
    }

    public function test_rejects_restore_without_snapshot_id(): void
    {
        $node = Node::factory()->create(['location_id' => $this->location->id]);
        $slot = ServerSlot::factory()->create([
            'node_id' => $node->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);
        $server = $this->createArchivedServer($node, ['archive_snapshot_id' => null]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->service->handle($slot, $server);
    }

    public function test_rolls_back_on_reinstall_failure(): void
    {
        $this->mock(DaemonServerRepository::class, function ($mock) {
            $mock->shouldReceive('setServer')->andReturnSelf();
            $mock->shouldReceive('reinstall')->andThrow(new \RuntimeException('Elytra error'));
        });

        $service = $this->app->make(ServerRestoreService::class);
        $node = Node::factory()->create(['location_id' => $this->location->id]);
        $slot = ServerSlot::factory()->create([
            'node_id' => $node->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);
        $server = $this->createArchivedServer($node);

        try {
            $service->handle($slot, $server);
        } catch (\RuntimeException) {
        }

        $slot->refresh();
        $this->assertEquals(ServerSlot::STATUS_IDLE, $slot->status);
        $this->assertNull($slot->active_server_id);

        $server->refresh();
        $this->assertEquals(Server::STATUS_ARCHIVED, $server->status);
    }
}
