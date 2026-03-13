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
use Pterodactyl\Services\Servers\ServerSwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Tests\TestCase;

class ServerSwapServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServerSwapService $service;
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
                    'uuid' => 'archive-job-uuid-456',
                    'type' => 'archive_create',
                    'status' => 'submitted',
                ]);
        });

        $this->service = $this->app->make(ServerSwapService::class);
    }

    /**
     * Helper to create a server with all required FK dependencies.
     */
    private function createServer(Node $node, array $overrides = []): Server
    {
        $user = $overrides['_user'] ?? User::factory()->create();
        $egg = Egg::factory()->create([
            'nest_id' => $this->nest->id,
            'author' => 'test@example.com',
            'docker_images' => ['ghcr.io/test:latest'],
            'config_stop' => 'stop',
            'config_startup' => '{"done": "Started"}',
            'config_logs' => '{}',
            'config_files' => '{}',
            'archive_excludes' => $overrides['_archive_excludes'] ?? [],
        ]);
        $allocation = Allocation::factory()->create(['node_id' => $node->id, 'server_id' => null]);

        unset($overrides['_user'], $overrides['_archive_excludes']);

        return Server::factory()->create(array_merge([
            'owner_id' => $user->id,
            'node_id' => $node->id,
            'allocation_id' => $allocation->id,
            'nest_id' => $this->nest->id,
            'egg_id' => $egg->id,
        ], $overrides));
    }

    public function test_initiates_swap_with_pending_deploy_and_correlation_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $node = Node::factory()->create(['location_id' => $this->location->id]);
        $server = $this->createServer($node);
        $newEgg = Egg::factory()->create([
            'nest_id' => $this->nest->id,
            'author' => 'test@example.com',
            'docker_images' => ['ghcr.io/test:latest'],
            'config_stop' => 'stop',
            'config_startup' => '{"done": "Started"}',
            'config_logs' => '{}',
            'config_files' => '{}',
        ]);
        $slot = ServerSlot::factory()->create([
            'node_id' => $node->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => $server->id,
            'pending_deploy_data' => null,
        ]);

        $result = $this->service->handle($slot, $newEgg, [
            'name' => 'New Server',
            'start_on_completion' => true,
        ]);

        $this->assertEquals('archive-job-uuid-456', $result['uuid']);

        $slot->refresh();
        $this->assertEquals(ServerSlot::STATUS_ARCHIVING, $slot->status);
        $this->assertNotNull($slot->pending_deploy_data);
        $this->assertEquals($newEgg->id, $slot->pending_deploy_data['egg_id']);
        $this->assertEquals('New Server', $slot->pending_deploy_data['name']);
        $this->assertEquals('archive-job-uuid-456', $slot->pending_deploy_data['correlation_token']);
    }

    public function test_rejects_swap_when_slot_has_no_active_server(): void
    {
        $slot = ServerSlot::factory()->create([
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);
        $egg = Egg::factory()->create([
            'nest_id' => $this->nest->id,
            'author' => 'test@example.com',
            'docker_images' => ['ghcr.io/test:latest'],
            'config_stop' => 'stop',
            'config_startup' => '{"done": "Started"}',
            'config_logs' => '{}',
            'config_files' => '{}',
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->service->handle($slot, $egg, ['name' => 'Test']);
    }

    public function test_does_not_write_pending_data_if_archive_fails(): void
    {
        $this->mock(ElytraJobService::class, function ($mock) {
            $mock->shouldReceive('submitJob')
                ->andThrow(new \RuntimeException('Elytra down'));
        });

        $service = $this->app->make(ServerSwapService::class);
        $node = Node::factory()->create(['location_id' => $this->location->id]);
        $server = $this->createServer($node);
        $slot = ServerSlot::factory()->create([
            'node_id' => $node->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => $server->id,
        ]);
        $newEgg = Egg::factory()->create([
            'nest_id' => $this->nest->id,
            'author' => 'test@example.com',
            'docker_images' => ['ghcr.io/test:latest'],
            'config_stop' => 'stop',
            'config_startup' => '{"done": "Started"}',
            'config_logs' => '{}',
            'config_files' => '{}',
        ]);

        try {
            $service->handle($slot, $newEgg, ['name' => 'Test']);
        } catch (\RuntimeException) {
        }

        $slot->refresh();
        $this->assertNull($slot->pending_deploy_data);
        $this->assertEquals(ServerSlot::STATUS_IDLE, $slot->status);
    }
}
