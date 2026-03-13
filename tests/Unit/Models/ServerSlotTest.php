<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Pterodactyl\Models\Plan;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ServerSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ServerSlotTest extends TestCase
{
    use RefreshDatabase;

    private function createNodeWithAllocation(): array
    {
        $location = Location::factory()->create();
        $node = Node::factory()->create(['location_id' => $location->id]);
        $allocation = Allocation::factory()->create(['node_id' => $node->id]);
        return [$node, $allocation];
    }

    public function testSlotCanBeCreatedWithValidAttributes(): void
    {
        $user = User::factory()->create();
        [$node, $allocation] = $this->createNodeWithAllocation();
        $plan = Plan::query()->create([
            'name' => 'Small', 'memory' => 1024, 'disk' => 10240, 'cpu' => 100,
        ]);

        $slot = ServerSlot::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'node_id' => $node->id,
            'allocation_id' => $allocation->id,
        ]);

        $this->assertDatabaseHas('server_slots', ['id' => $slot->id]);
        $this->assertEquals('idle', $slot->status);
    }

    public function testSlotBelongsToUserPlanAndNode(): void
    {
        $user = User::factory()->create();
        [$node, $allocation] = $this->createNodeWithAllocation();
        $plan = Plan::query()->create([
            'name' => 'Small', 'memory' => 1024, 'disk' => 10240, 'cpu' => 100,
        ]);

        $slot = ServerSlot::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'node_id' => $node->id,
            'allocation_id' => $allocation->id,
        ]);

        $this->assertEquals($user->id, $slot->user->id);
        $this->assertEquals($plan->id, $slot->plan->id);
        $this->assertEquals($node->id, $slot->node->id);
    }

    public function testEffectiveResourcesUseOverrideWhenSet(): void
    {
        $user = User::factory()->create();
        [$node, $allocation] = $this->createNodeWithAllocation();
        $plan = Plan::query()->create([
            'name' => 'Small', 'memory' => 1024, 'disk' => 10240, 'cpu' => 100,
            'io' => 500, 'swap' => 0,
        ]);

        $slot = ServerSlot::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'node_id' => $node->id,
            'allocation_id' => $allocation->id,
            'memory_override' => 2048,
        ]);

        $this->assertEquals(2048, $slot->effectiveMemory());
        $this->assertEquals(10240, $slot->effectiveDisk());
        $this->assertEquals(100, $slot->effectiveCpu());
    }

    public function testSlotIsIdleByDefault(): void
    {
        $user = User::factory()->create();
        [$node, $allocation] = $this->createNodeWithAllocation();
        $plan = Plan::query()->create([
            'name' => 'Small', 'memory' => 1024, 'disk' => 10240, 'cpu' => 100,
        ]);

        $slot = ServerSlot::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'node_id' => $node->id,
            'allocation_id' => $allocation->id,
        ]);

        $this->assertTrue($slot->isIdle());
        $this->assertNull($slot->active_server_id);
    }
}
