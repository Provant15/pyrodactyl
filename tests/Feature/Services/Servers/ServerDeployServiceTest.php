<?php

namespace Pterodactyl\Tests\Feature\Services\Servers;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Plan;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Servers\ServerCreationService;
use Pterodactyl\Services\Servers\ServerDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Pterodactyl\Tests\TestCase;

class ServerDeployServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServerDeployService $service;
    private Nest $nest;
    private Location $location;

    public function setUp(): void
    {
        parent::setUp();

        $this->nest = Nest::factory()->create();
        $this->location = Location::factory()->create();

        $this->mock(ServerCreationService::class, function ($mock) {
            $mock->shouldReceive('handle')
                ->andReturnUsing(function ($data) {
                    $node = Node::find($data['node_id']);
                    $allocation = Allocation::find($data['allocation_id']);
                    $egg = Egg::find($data['egg_id']);

                    return Server::factory()->create([
                        'name' => $data['name'],
                        'owner_id' => $data['owner_id'],
                        'node_id' => $data['node_id'],
                        'allocation_id' => $data['allocation_id'],
                        'nest_id' => $data['nest_id'],
                        'egg_id' => $data['egg_id'],
                        'memory' => $data['memory'],
                        'disk' => $data['disk'],
                        'cpu' => $data['cpu'],
                        'status' => Server::STATUS_INSTALLING,
                    ]);
                });
        });

        $this->service = $this->app->make(ServerDeployService::class);
    }

    public function test_deploys_egg_to_idle_empty_slot(): void
    {
        $user = User::factory()->create();
        $node = Node::factory()->create(['location_id' => $this->location->id]);
        $plan = Plan::factory()->create(['memory' => 4096, 'disk' => 20480, 'cpu' => 200]);
        $allocation = Allocation::factory()->create(['node_id' => $node->id, 'server_id' => null]);
        $egg = Egg::factory()->create([
            'nest_id' => $this->nest->id,
            'author' => 'test@example.com',
            'docker_images' => ['ghcr.io/test:latest'],
            'config_stop' => 'stop',
            'config_startup' => '{"done": "Started"}',
            'config_logs' => '{}',
            'config_files' => '{}',
            'min_memory' => null,
        ]);

        $slot = ServerSlot::factory()->create([
            'user_id' => $user->id,
            'node_id' => $node->id,
            'plan_id' => $plan->id,
            'allocation_id' => $allocation->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);

        $server = $this->service->handle($slot, $egg, [
            'name' => 'My Minecraft Server',
            'start_on_completion' => true,
        ]);

        $this->assertInstanceOf(Server::class, $server);

        $slot->refresh();
        $this->assertEquals($server->id, $slot->active_server_id);
        $this->assertEquals(ServerSlot::STATUS_IDLE, $slot->status);
    }

    public function test_rejects_deployment_to_non_idle_slot(): void
    {
        $slot = ServerSlot::factory()->create([
            'status' => ServerSlot::STATUS_ARCHIVING,
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

    public function test_rejects_deployment_when_slot_has_active_server(): void
    {
        $slot = ServerSlot::factory()->create([
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => 1,
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

    public function test_rejects_deployment_when_resources_below_egg_minimum(): void
    {
        $plan = Plan::factory()->create(['memory' => 1024]);
        $slot = ServerSlot::factory()->create([
            'plan_id' => $plan->id,
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
            'min_memory' => 4096,
        ]);

        $this->expectException(UnprocessableEntityHttpException::class);

        $this->service->handle($slot, $egg, ['name' => 'Test']);
    }
}
