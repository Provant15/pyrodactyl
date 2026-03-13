<?php

namespace Pterodactyl\Tests\Feature\Services\Slots;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Elytra\ElytraJobService;
use Pterodactyl\Services\Slots\SlotDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Tests\TestCase;

class SlotDeletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SlotDeletionService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->mock(ElytraJobService::class);
        $this->service = $this->app->make(SlotDeletionService::class);
    }

    public function test_deletes_idle_slot_with_no_servers(): void
    {
        $slot = ServerSlot::factory()->create([
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);

        $this->service->handle($slot);

        $this->assertDatabaseMissing('server_slots', ['id' => $slot->id]);
    }

    public function test_rejects_deletion_when_slot_has_active_server(): void
    {
        $slot = ServerSlot::factory()->create([
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => 1,
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('active server');

        $this->service->handle($slot);
    }

    public function test_rejects_deletion_when_slot_is_not_idle(): void
    {
        $slot = ServerSlot::factory()->create([
            'status' => ServerSlot::STATUS_DEPLOYING,
            'active_server_id' => null,
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('not idle');

        $this->service->handle($slot);
    }

    public function test_deletes_slot_with_archived_servers_and_submits_cleanup(): void
    {
        // Re-mock with expectations and re-resolve the service
        $this->mock(ElytraJobService::class, function ($mock) {
            $mock->shouldReceive('submitJob')
                ->once()
                ->andReturn(['uuid' => 'job-uuid', 'status' => 'submitted']);
        });
        $service = $this->app->make(SlotDeletionService::class);

        $user = User::factory()->create();
        $location = Location::factory()->create();
        $node = Node::factory()->create(['location_id' => $location->id]);
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

        $slotAllocation = Allocation::factory()->create([
            'node_id' => $node->id,
            'server_id' => null,
        ]);

        $slot = ServerSlot::factory()->create([
            'user_id' => $user->id,
            'node_id' => $node->id,
            'allocation_id' => $slotAllocation->id,
            'status' => ServerSlot::STATUS_IDLE,
            'active_server_id' => null,
        ]);

        // Create an archived server - archived servers don't need an allocation
        $archivedAllocation = Allocation::factory()->create([
            'node_id' => $node->id,
            'server_id' => null,
        ]);

        $archived = Server::factory()->create([
            'owner_id' => $user->id,
            'node_id' => $node->id,
            'allocation_id' => $archivedAllocation->id,
            'nest_id' => $nest->id,
            'egg_id' => $egg->id,
            'slot_id' => $slot->id,
            'status' => Server::STATUS_ARCHIVED,
            'archive_snapshot_id' => 'snap-abc123',
        ]);

        $service->handle($slot);

        $this->assertDatabaseMissing('server_slots', ['id' => $slot->id]);
        $this->assertDatabaseMissing('servers', ['id' => $archived->id]);
    }
}
