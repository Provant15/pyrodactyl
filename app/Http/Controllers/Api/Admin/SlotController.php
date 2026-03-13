<?php

namespace Pterodactyl\Http\Controllers\Api\Admin;

use Pterodactyl\Http\Resources\Admin\SlotResource;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Services\Slots\SlotCreationService;
use Pterodactyl\Services\Slots\SlotDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin API controller for server slot management.
 *
 * Provides CRUD operations for slots and lifecycle actions (deploy, archive,
 * restore) that are handled by dedicated service classes.
 */
class SlotController extends AdminApiController
{
    public function __construct(
        private SlotCreationService $creationService,
        private SlotDeletionService $deletionService,
    ) {}

    /**
     * List all server slots with optional filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $slots = ServerSlot::query()
            ->with(['plan', 'user', 'node'])
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('node_id'), fn ($q, $v) => $q->where('node_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderBy('created_at', 'desc')
            ->paginate(min($request->query('per_page', 25), 100));

        return SlotResource::collection($slots);
    }

    /**
     * Get a single slot with full relationship data.
     */
    public function show(ServerSlot $slot): SlotResource
    {
        $slot->load(['plan', 'user', 'node', 'activeServer', 'archivedServers.egg']);

        return new SlotResource($slot);
    }

    /**
     * Create a new server slot.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'node_id' => 'required|exists:nodes,id',
            'plan_id' => 'required|exists:plans,id',
            'allocation_id' => 'required|exists:allocations,id',
            'label' => 'nullable|string|max:255',
            'memory_override' => 'nullable|integer|min:0',
            'disk_override' => 'nullable|integer|min:0',
            'cpu_override' => 'nullable|integer|min:0',
            'io_override' => 'nullable|integer|min:10|max:1000',
            'swap_override' => 'nullable|integer|min:-1',
        ]);

        $slot = $this->creationService->handle($validated);
        $slot->load(['plan', 'user', 'node']);

        return $this->returnCreated(new SlotResource($slot));
    }

    /**
     * Update a slot's label, plan, or resource overrides.
     */
    public function update(Request $request, ServerSlot $slot): SlotResource
    {
        $validated = $request->validate([
            'label' => 'sometimes|nullable|string|max:255',
            'plan_id' => 'sometimes|exists:plans,id',
            'memory_override' => 'sometimes|nullable|integer|min:0',
            'disk_override' => 'sometimes|nullable|integer|min:0',
            'cpu_override' => 'sometimes|nullable|integer|min:0',
            'io_override' => 'sometimes|nullable|integer|min:10|max:1000',
            'swap_override' => 'sometimes|nullable|integer|min:-1',
        ]);

        $slot->update($validated);
        $slot->load(['plan', 'user', 'node']);

        return new SlotResource($slot);
    }

    /**
     * Delete a slot and its archived servers.
     */
    public function destroy(ServerSlot $slot): JsonResponse
    {
        $this->deletionService->handle($slot);

        return $this->returnNoContent();
    }
}
