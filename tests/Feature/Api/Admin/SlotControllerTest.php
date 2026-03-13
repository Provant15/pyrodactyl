<?php

namespace Pterodactyl\Tests\Feature\Api\Admin;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Plan;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pterodactyl\Tests\TestCase;

class SlotControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    public function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['root_admin' => true]);
    }

    public function test_index_returns_paginated_slots(): void
    {
        ServerSlot::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/slots');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonStructure([
            'data' => [['id', 'user_id', 'node_id', 'plan_id', 'status']],
            'meta',
        ]);
    }

    public function test_show_returns_slot_with_relationships(): void
    {
        $slot = ServerSlot::factory()->create();

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/slots/{$slot->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $slot->id);
    }

    public function test_store_creates_slot(): void
    {
        $user = User::factory()->create();
        $node = Node::factory()->create();
        $plan = Plan::factory()->create();
        $allocation = Allocation::factory()->create([
            'node_id' => $node->id, 'server_id' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/slots', [
                'user_id' => $user->id,
                'node_id' => $node->id,
                'plan_id' => $plan->id,
                'allocation_id' => $allocation->id,
                'label' => 'Test Slot',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.label', 'Test Slot');
    }

    public function test_update_modifies_slot(): void
    {
        $slot = ServerSlot::factory()->create(['label' => 'Old Label']);
        $newPlan = Plan::factory()->create();

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/admin/slots/{$slot->id}", [
                'label' => 'New Label',
                'plan_id' => $newPlan->id,
                'memory_override' => 8192,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.label', 'New Label');
        $response->assertJsonPath('data.memory_override', 8192);
    }

    public function test_destroy_deletes_idle_empty_slot(): void
    {
        $slot = ServerSlot::factory()->create([
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/admin/slots/{$slot->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('server_slots', ['id' => $slot->id]);
    }

    public function test_destroy_rejects_slot_with_active_server(): void
    {
        $slot = ServerSlot::factory()->create([
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => 1,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/admin/slots/{$slot->id}");

        $response->assertStatus(409);
    }

    public function test_unauthenticated_user_cannot_access_slots(): void
    {
        $response = $this->getJson('/api/admin/slots');
        $response->assertUnauthorized();
    }
}
