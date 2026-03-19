<?php

namespace Pterodactyl\Http\Controllers\Api\Admin;

use Pterodactyl\Http\Resources\Admin\SlotResource;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Services\Elytra\ElytraJobService;
use Pterodactyl\Services\Servers\ServerArchiveService;
use Pterodactyl\Services\Servers\ServerDeployService;
use Pterodactyl\Services\Servers\ServerRestoreService;
use Pterodactyl\Services\Servers\ServerSwapService;
use Pterodactyl\Services\Slots\SlotCreationService;
use Pterodactyl\Services\Slots\SlotDeletionService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        private ServerDeployService $deployService,
        private ServerArchiveService $archiveService,
        private ServerRestoreService $restoreService,
        private ServerSwapService $swapService,
        private ElytraJobService $elytraJobService,
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
            'port' => 'nullable|integer|min:1024|max:65535',
            'allocation_id' => 'nullable|exists:allocations,id',
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

    /**
     * Deploy an egg to a slot, or swap if slot has an active server.
     */
    public function deploy(Request $request, ServerSlot $slot): JsonResponse
    {
        $validated = $request->validate([
            'egg_id' => 'required|exists:eggs,id',
            'name' => 'required|string|max:255',
            'start_on_completion' => 'boolean',
            'skip_validation' => 'boolean',
        ]);

        $egg = Egg::findOrFail($validated['egg_id']);

        if ($slot->active_server_id !== null) {
            $result = $this->swapService->handle($slot, $egg, $validated);

            return new JsonResponse([
                'message' => 'Server swap initiated. Current server is being archived.',
                'job_id' => $result['job_id'] ?? null,
            ], JsonResponse::HTTP_ACCEPTED);
        }

        $server = $this->deployService->handle($slot, $egg, $validated);

        return new JsonResponse([
            'data' => (new SlotResource($slot->fresh(['plan', 'activeServer'])))->resolve(),
        ]);
    }

    /**
     * Archive the slot's active server.
     */
    public function archive(ServerSlot $slot): JsonResponse
    {
        $result = $this->archiveService->handle($slot);

        return new JsonResponse([
            'message' => 'Archive initiated.',
            'job_id' => $result['job_id'] ?? null,
        ], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * Restore an archived server to this slot.
     */
    public function restore(Request $request, ServerSlot $slot): JsonResponse
    {
        $validated = $request->validate([
            'server_id' => 'required|exists:servers,id',
        ]);

        $server = Server::where('id', $validated['server_id'])
            ->where('status', Server::STATUS_ARCHIVED)
            ->firstOrFail();

        $this->restoreService->handle($slot, $server);

        return new JsonResponse([
            'message' => 'Restore initiated. Reinstalling game binaries (Phase 1).',
        ], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * Delete an individual archived server and its Rustic snapshot.
     */
    public function deleteArchive(Request $request, ServerSlot $slot, Server $server): JsonResponse
    {
        if ($server->slot_id !== $slot->id || $server->status !== Server::STATUS_ARCHIVED) {
            throw new NotFoundHttpException('Archived server not found on this slot.');
        }

        if ($server->archive_snapshot_id) {
            try {
                $this->elytraJobService->submitJob(
                    $server,
                    'archive_delete',
                    ['snapshot_id' => $server->archive_snapshot_id, 'operation' => 'archive_delete'],
                    $request->user(),
                );
            } catch (\Throwable $e) {
                logger()->warning('Failed to submit snapshot cleanup for archive deletion', [
                    'slot_id' => $slot->id,
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $server->delete();

        return $this->returnNoContent();
    }
}
