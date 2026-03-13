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
use Pterodactyl\Services\Elytra\ElytraJobService;
use Pterodactyl\Services\Servers\ServerArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Tests\TestCase;

class ServerArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServerArchiveService $service;
    private Nest $nest;
    private Location $location;

    public function setUp(): void
    {
        parent::setUp();

        $this->nest = Nest::factory()->create();
        $this->location = Location::factory()->create();

        $this->mock(ElytraJobService::class, function ($mock) {
            $mock->shouldReceive('submitJob')
                ->andReturn([
                    'uuid' => 'elytra-job-uuid-123',
                    'type' => 'archive_create',
                    'status' => 'submitted',
                ]);
        });

        $this->service = $this->app->make(ServerArchiveService::class);
    }

    /**
     * Helper to create a full server with all required FK dependencies.
     */
    private function createServer(array $overrides = []): Server
    {
        $user = $overrides['_user'] ?? User::factory()->create();
        $node = $overrides['_node'] ?? Node::factory()->create(['location_id' => $this->location->id]);
        $allocation = Allocation::factory()->create(['node_id' => $node->id, 'server_id' => null]);

        $defaults = [
            'owner_id' => $user->id,
            'node_id' => $node->id,
            'allocation_id' => $allocation->id,
            'nest_id' => $this->nest->id,
        ];

        unset($overrides['_user'], $overrides['_node']);

        return Server::factory()->create(array_merge($defaults, $overrides));
    }

    public function test_initiates_archive_for_active_server(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $node = Node::factory()->create(['location_id' => $this->location->id]);
        $egg = Egg::factory()->create([
            'nest_id' => $this->nest->id,
            'author' => 'test@example.com',
            'docker_images' => ['ghcr.io/test:latest'],
            'config_stop' => 'stop',
            'config_startup' => '{"done": "Started"}',
            'config_logs' => '{}',
            'config_files' => '{}',
            'archive_excludes' => ['*.jar', 'logs/**'],
        ]);
        $server = $this->createServer([
            '_node' => $node,
            'egg_id' => $egg->id,
            'status' => null,
        ]);
        $slot = ServerSlot::factory()->create([
            'node_id' => $node->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => $server->id,
        ]);

        $result = $this->service->handle($slot);

        $this->assertEquals('elytra-job-uuid-123', $result['uuid']);

        $slot->refresh();
        $this->assertEquals(ServerSlot::STATUS_ARCHIVING, $slot->status);
    }

    public function test_rejects_archive_when_slot_has_no_active_server(): void
    {
        $slot = ServerSlot::factory()->create([
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->service->handle($slot);
    }

    public function test_rejects_archive_when_slot_is_not_idle(): void
    {
        $slot = ServerSlot::factory()->create([
            'status' => ServerSlot::STATUS_DEPLOYING,
            'active_server_id' => 1,
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->service->handle($slot);
    }

    public function test_unlocks_slot_on_submission_failure(): void
    {
        $this->mock(ElytraJobService::class, function ($mock) {
            $mock->shouldReceive('submitJob')
                ->andThrow(new \RuntimeException('Elytra unreachable'));
        });

        $service = $this->app->make(ServerArchiveService::class);

        $node = Node::factory()->create(['location_id' => $this->location->id]);
        $egg = Egg::factory()->create([
            'nest_id' => $this->nest->id,
            'author' => 'test@example.com',
            'docker_images' => ['ghcr.io/test:latest'],
            'config_stop' => 'stop',
            'config_startup' => '{"done": "Started"}',
            'config_logs' => '{}',
            'config_files' => '{}',
        ]);
        $server = $this->createServer([
            '_node' => $node,
            'egg_id' => $egg->id,
        ]);
        $slot = ServerSlot::factory()->create([
            'node_id' => $node->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => $server->id,
        ]);

        try {
            $service->handle($slot);
        } catch (\RuntimeException) {
        }

        $slot->refresh();
        $this->assertEquals(ServerSlot::STATUS_IDLE, $slot->status);
    }
}
