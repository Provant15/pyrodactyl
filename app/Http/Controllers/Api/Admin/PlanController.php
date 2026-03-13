<?php

namespace Pterodactyl\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Plan;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Http\Resources\Admin\PlanResource;
use Pterodactyl\Services\Activity\ActivityLogService;
use Pterodactyl\Services\Plans\PlanCreationService;
use Pterodactyl\Services\Plans\PlanUpdateService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Handles CRUD operations for resource plans (templates).
 */
class PlanController extends AdminApiController
{
    public function __construct(
        private PlanCreationService $creationService,
        private PlanUpdateService $updateService,
        private ActivityLogService $activityLog,
    ) {
    }

    /**
     * Lists all plans with slot counts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $plans = Plan::query()
            ->withCount('slots')
            ->orderBy('name')
            ->paginate(min($request->query('per_page', 25), 100));

        return PlanResource::collection($plans);
    }

    /**
     * Shows a single plan.
     */
    public function show(int $plan): PlanResource
    {
        $plan = Plan::query()
            ->withCount('slots')
            ->findOrFail($plan);

        return new PlanResource($plan);
    }

    /**
     * Creates a new plan.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'description' => 'nullable|string',
            'memory' => 'required|integer|min:0',
            'disk' => 'required|integer|min:0',
            'cpu' => 'required|integer|min:0',
            'io' => 'sometimes|integer|between:10,1000',
            'swap' => 'sometimes|integer|min:-1',
            'oom_disabled' => 'sometimes|boolean',
            'threads' => 'nullable|regex:/^[0-9-,]+$/',
            'databases_limit' => 'sometimes|integer|min:0',
            'backups_limit' => 'sometimes|integer|min:0',
            'allocations_limit' => 'sometimes|integer|min:0',
            'archive_limit' => 'sometimes|integer|min:0',
            'is_default' => 'sometimes|boolean',
        ]);

        $plan = $this->creationService->handle($validated);

        return $this->returnCreated(new PlanResource($plan));
    }

    /**
     * Updates an existing plan.
     */
    public function update(Request $request, int $plan): PlanResource
    {
        $plan = Plan::findOrFail($plan);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:191',
            'description' => 'nullable|string',
            'memory' => 'sometimes|integer|min:0',
            'disk' => 'sometimes|integer|min:0',
            'cpu' => 'sometimes|integer|min:0',
            'io' => 'sometimes|integer|between:10,1000',
            'swap' => 'sometimes|integer|min:-1',
            'oom_disabled' => 'sometimes|boolean',
            'threads' => 'nullable|regex:/^[0-9-,]+$/',
            'databases_limit' => 'sometimes|integer|min:0',
            'backups_limit' => 'sometimes|integer|min:0',
            'allocations_limit' => 'sometimes|integer|min:0',
            'archive_limit' => 'sometimes|integer|min:0',
            'is_default' => 'sometimes|boolean',
        ]);

        $updated = $this->updateService->handle($plan, $validated);

        return new PlanResource($updated->loadCount('slots'));
    }

    /**
     * Deletes a plan.
     */
    public function destroy(int $plan): JsonResponse
    {
        $plan = Plan::withCount('slots')->findOrFail($plan);

        if ($plan->slots_count > 0) {
            abort(422, 'Cannot delete a plan that has active slots. Reassign or delete the slots first.');
        }

        $plan->delete();

        return $this->returnNoContent();
    }

    /**
     * Propagate a plan's current resource values to active servers on slots using this plan.
     *
     * Only updates servers where the slot has no override for the given resource.
     * Dry-run mode (preview=true) returns affected servers without making changes.
     */
    public function propagate(Request $request, Plan $plan): JsonResponse
    {
        $preview = $request->boolean('preview', false);

        $slots = ServerSlot::where('plan_id', $plan->id)
            ->whereNotNull('active_server_id')
            ->with(['activeServer'])
            ->get();

        $affected = [];

        foreach ($slots as $slot) {
            $server = $slot->activeServer;
            if (!$server) {
                continue;
            }

            $changes = [];

            if ($slot->memory_override === null && $server->memory !== $plan->memory) {
                $changes['memory'] = ['from' => $server->memory, 'to' => $plan->memory];
            }
            if ($slot->disk_override === null && $server->disk !== $plan->disk) {
                $changes['disk'] = ['from' => $server->disk, 'to' => $plan->disk];
            }
            if ($slot->cpu_override === null && $server->cpu !== $plan->cpu) {
                $changes['cpu'] = ['from' => $server->cpu, 'to' => $plan->cpu];
            }
            if ($slot->io_override === null && $server->io !== $plan->io) {
                $changes['io'] = ['from' => $server->io, 'to' => $plan->io];
            }
            if ($slot->swap_override === null && $server->swap !== $plan->swap) {
                $changes['swap'] = ['from' => $server->swap, 'to' => $plan->swap];
            }

            if (!empty($changes)) {
                $affected[] = [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'slot_id' => $slot->id,
                    'changes' => $changes,
                ];

                if (!$preview) {
                    $server->update(array_map(fn ($c) => $c['to'], $changes));
                }
            }
        }

        if (!$preview && !empty($affected)) {
            $this->activityLog
                ->event('admin:plan.propagated')
                ->subject($plan)
                ->property(['affected_count' => count($affected)])
                ->log();
        }

        return new JsonResponse([
            'preview' => $preview,
            'affected_count' => count($affected),
            'affected_servers' => $affected,
        ]);
    }
}
