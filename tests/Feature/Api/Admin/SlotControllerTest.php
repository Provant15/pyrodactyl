<?php

namespace Pterodactyl\Tests\Feature\Api\Admin;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Plan;
use Pterodactyl\Models\Server;
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

    /**
     * Creates a full server with all required dependencies.
     *
     * @param array<string, mixed> $overrides
     */
    private function createServer(array $overrides = []): Server
    {
        $location = Location::factory()->create();
        $node = $overrides['node_id']
            ? Node::find($overrides['node_id'])
            : Node::factory()->create(['location_id' => $location->id]);
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
        $user = User::factory()->create();
        $allocation = Allocation::factory()->create([
            'node_id' => $node->id,
            'server_id' => null,
        ]);

        $defaults = [
            'owner_id' => $user->id,
            'node_id' => $node->id,
            'allocation_id' => $allocation->id,
            'nest_id' => $nest->id,
            'egg_id' => $egg->id,
        ];

        // For archived servers, allocation_id is not required
        if (($overrides['status'] ?? null) === Server::STATUS_ARCHIVED) {
            $defaults['allocation_id'] = null;
            unset($overrides['allocation_id']);
        }

        return Server::factory()->create(array_merge($defaults, $overrides));
    }

    /**
     * Creates a node with proper location dependency.
     */
    private function createNode(): Node
    {
        $location = Location::factory()->create();

        return Node::factory()->create(['location_id' => $location->id]);
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
        $node = $this->createNode();
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

        // The AuthenticateAdminUser middleware throws AccessDeniedHttpException (403)
        // when no user is authenticated, since auth.session passes but user is null.
        $response->assertForbidden();
    }

    public function test_deploy_creates_server_on_empty_slot(): void
    {
        $node = $this->createNode();
        $server = $this->createServer(['node_id' => $node->id, 'status' => 'installing']);

        $this->mock(\Pterodactyl\Services\Servers\ServerCreationService::class, function ($mock) use ($server) {
            $mock->shouldReceive('handle')->andReturn($server);
        });

        $nest = Nest::factory()->create();
        $egg = Egg::factory()->create([
            'nest_id' => $nest->id,
            'min_memory' => null,
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
            'active_server_id' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/slots/{$slot->id}/deploy", [
                'egg_id' => $egg->id,
                'name' => 'My Server',
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['id']]);
    }

    public function test_archive_initiates_archival(): void
    {
        $this->mock(\Pterodactyl\Services\Elytra\ElytraJobService::class, function ($mock) {
            $mock->shouldReceive('submitJob')
                ->andReturn(['job_id' => 'job-123', 'status' => 'submitted']);
        });

        $node = $this->createNode();
        $server = $this->createServer(['node_id' => $node->id]);
        $slot = ServerSlot::factory()->create([
            'node_id' => $node->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => $server->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/slots/{$slot->id}/archive");

        $response->assertAccepted();
        $response->assertJsonPath('job_id', 'job-123');
    }

    public function test_restore_initiates_restoration(): void
    {
        $this->mock(\Pterodactyl\Repositories\Wings\DaemonServerRepository::class, function ($mock) {
            $mock->shouldReceive('setServer')->andReturnSelf();
            $mock->shouldReceive('reinstall');
        });

        $node = $this->createNode();
        $server = $this->createServer([
            'node_id' => $node->id,
            'status' => Server::STATUS_ARCHIVED,
            'archive_snapshot_id' => 'snap-abc',
        ]);
        $slot = ServerSlot::factory()->create([
            'node_id' => $node->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/slots/{$slot->id}/restore", [
                'server_id' => $server->id,
            ]);

        $response->assertAccepted();
    }
}
