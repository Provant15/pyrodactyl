<?php

namespace Pterodactyl\Services\Slots;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ServerSlot;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Services\Activity\ActivityLogService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Creates a new server slot assigned to a user on a specific node.
 *
 * Supports two allocation modes:
 * - Auto-assign: if no port is specified, picks the next available allocation on the node.
 * - Specific port: if a port number is provided, finds or creates an allocation for it.
 *
 * The slot starts in idle status with no active server.
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
     *     allocation_id?: int,
     *     port?: int|null,
     *     label?: string|null,
     *     memory_override?: int|null,
     *     disk_override?: int|null,
     *     cpu_override?: int|null,
     *     io_override?: int|null,
     *     swap_override?: int|null,
     * } $data
     * @return ServerSlot
     *
     * @throws ConflictHttpException If no allocations are available or port is taken
     */
    public function handle(array $data): ServerSlot
    {
        return $this->connection->transaction(function () use ($data) {
            $allocationId = $this->resolveAllocation($data);

            $slot = ServerSlot::create([
                'user_id' => $data['user_id'],
                'node_id' => $data['node_id'],
                'plan_id' => $data['plan_id'],
                'allocation_id' => $allocationId,
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
                ->property([
                    'label' => $slot->label,
                    'plan_id' => $slot->plan_id,
                    'allocation_id' => $allocationId,
                ])
                ->log();

            return $slot;
        });
    }

    /**
     * Resolve an allocation for the slot. Supports three modes:
     * 1. Explicit allocation_id (legacy): validate it belongs to the node and is free.
     * 2. Specific port: find an existing unassigned allocation on that port, or create one.
     * 3. Auto-assign: pick the first available unassigned allocation on the node.
     */
    private function resolveAllocation(array $data): int
    {
        $nodeId = $data['node_id'];

        // Mode 1: explicit allocation_id (backwards compatible)
        if (!empty($data['allocation_id'])) {
            $allocation = Allocation::query()
                ->where('id', $data['allocation_id'])
                ->where('node_id', $nodeId)
                ->whereNull('server_id')
                ->lockForUpdate()
                ->first();

            if (!$allocation) {
                throw new ConflictHttpException('Allocation is not available or does not belong to this node.');
            }

            return $allocation->id;
        }

        // Mode 2: specific port requested
        if (!empty($data['port'])) {
            $port = (int) $data['port'];

            // Check if this port is already in use on any IP on this node
            $taken = Allocation::query()
                ->where('node_id', $nodeId)
                ->where('port', $port)
                ->whereNotNull('server_id')
                ->exists();

            if ($taken) {
                throw new ConflictHttpException("Port {$port} is already in use on this node.");
            }

            // Find an existing unassigned allocation with this port
            $allocation = Allocation::query()
                ->where('node_id', $nodeId)
                ->where('port', $port)
                ->whereNull('server_id')
                ->lockForUpdate()
                ->first();

            if ($allocation) {
                return $allocation->id;
            }

            // Create a new allocation for this port
            $allocation = Allocation::create([
                'node_id' => $nodeId,
                'ip' => '0.0.0.0',
                'port' => $port,
            ]);

            return $allocation->id;
        }

        // Mode 3: auto-assign next available
        $allocation = Allocation::query()
            ->where('node_id', $nodeId)
            ->whereNull('server_id')
            ->lockForUpdate()
            ->orderBy('port')
            ->first();

        if (!$allocation) {
            throw new ConflictHttpException('No available ports on this node. Add allocations or free existing ones.');
        }

        return $allocation->id;
    }
}
