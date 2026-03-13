<?php

namespace Pterodactyl\Services\Slots;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ServerSlot;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Services\Activity\ActivityLogService;

/**
 * Creates a new server slot assigned to a user on a specific node.
 *
 * Validates that the specified allocation belongs to the target node and
 * is not already assigned to another server. The slot starts in idle status.
 */
class SlotCreationService
{
    public function __construct(
        private ConnectionInterface $connection,
        private ActivityLogService $activityLog,
    ) {}

    /**
     * Create a new server slot.
     *
     * @param array{
     *     user_id: int,
     *     node_id: int,
     *     plan_id: int,
     *     allocation_id: int,
     *     label?: string|null,
     *     memory_override?: int|null,
     *     disk_override?: int|null,
     *     cpu_override?: int|null,
     *     io_override?: int|null,
     *     swap_override?: int|null,
     * } $data
     * @return ServerSlot
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If allocation is invalid
     */
    public function handle(array $data): ServerSlot
    {
        return $this->connection->transaction(function () use ($data) {
            // Verify allocation belongs to the node and is unassigned
            Allocation::query()
                ->where('id', $data['allocation_id'])
                ->where('node_id', $data['node_id'])
                ->whereNull('server_id')
                ->lockForUpdate()
                ->firstOrFail();

            $slot = ServerSlot::create([
                'user_id' => $data['user_id'],
                'node_id' => $data['node_id'],
                'plan_id' => $data['plan_id'],
                'allocation_id' => $data['allocation_id'],
                'label' => $data['label'] ?? null,
                'status' => ServerSlot::STATUS_IDLE,
                'memory_override' => $data['memory_override'] ?? null,
                'disk_override' => $data['disk_override'] ?? null,
                'cpu_override' => $data['cpu_override'] ?? null,
                'io_override' => $data['io_override'] ?? null,
                'swap_override' => $data['swap_override'] ?? null,
            ]);

            $this->activityLog
                ->event('admin:slot.created')
                ->subject($slot)
                ->property(['label' => $slot->label, 'plan_id' => $slot->plan_id])
                ->log();

            return $slot;
        });
    }
}
