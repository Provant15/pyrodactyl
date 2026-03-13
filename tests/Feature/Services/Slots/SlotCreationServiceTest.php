<?php

namespace Pterodactyl\Tests\Feature\Services\Slots;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Plan;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Slots\SlotCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Pterodactyl\Tests\TestCase;

class SlotCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SlotCreationService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(SlotCreationService::class);
    }

    public function test_creates_slot_with_valid_data(): void
    {
        $user = User::factory()->create();
        $location = Location::factory()->create();
        $node = Node::factory()->create(['location_id' => $location->id]);
        $plan = Plan::factory()->create();
        $allocation = Allocation::factory()->create([
            'node_id' => $node->id,
            'server_id' => null,
        ]);

        $slot = $this->service->handle([
            'user_id' => $user->id,
            'node_id' => $node->id,
            'plan_id' => $plan->id,
            'allocation_id' => $allocation->id,
            'label' => 'My Gaming Slot',
        ]);

        $this->assertInstanceOf(ServerSlot::class, $slot);
        $this->assertEquals($user->id, $slot->user_id);
        $this->assertEquals($node->id, $slot->node_id);
        $this->assertEquals($plan->id, $slot->plan_id);
        $this->assertEquals($allocation->id, $slot->allocation_id);
        $this->assertEquals('My Gaming Slot', $slot->label);
        $this->assertEquals(ServerSlot::STATUS_IDLE, $slot->status);
    }

    public function test_rejects_allocation_from_different_node(): void
    {
        $user = User::factory()->create();
        $location = Location::factory()->create();
        $node = Node::factory()->create(['location_id' => $location->id]);
        $otherNode = Node::factory()->create(['location_id' => $location->id]);
        $plan = Plan::factory()->create();
        $allocation = Allocation::factory()->create([
            'node_id' => $otherNode->id,
            'server_id' => null,
        ]);

        $this->expectException(ModelNotFoundException::class);

        $this->service->handle([
            'user_id' => $user->id,
            'node_id' => $node->id,
            'plan_id' => $plan->id,
            'allocation_id' => $allocation->id,
        ]);
    }

    public function test_rejects_already_assigned_allocation(): void
    {
        $user = User::factory()->create();
        $location = Location::factory()->create();
        $node = Node::factory()->create(['location_id' => $location->id]);
        $plan = Plan::factory()->create();
        $nest = Nest::factory()->create();
        $egg = Egg::factory()->create([
            'nest_id' => $nest->id,
            'author' => 'test@example.com',
            'docker_images' => ['ghcr.io/test:latest'],
            'config_stop' => 'stop',
            'config_startup' => '{"done": "Started"}',
            'config_logs' => '{}',
            'config_files' => '{}',
        ]);

        // Create a real server to own the allocation
        $allocation = Allocation::factory()->create([
            'node_id' => $node->id,
            'server_id' => null,
        ]);
        Server::factory()->create([
            'owner_id' => $user->id,
            'node_id' => $node->id,
            'allocation_id' => $allocation->id,
            'nest_id' => $nest->id,
            'egg_id' => $egg->id,
        ]);
        // After server creation, the allocation should be assigned
        $allocation->update(['server_id' => Server::query()->latest('id')->first()->id]);

        $this->expectException(ModelNotFoundException::class);

        $this->service->handle([
            'user_id' => $user->id,
            'node_id' => $node->id,
            'plan_id' => $plan->id,
            'allocation_id' => $allocation->id,
        ]);
    }
}
