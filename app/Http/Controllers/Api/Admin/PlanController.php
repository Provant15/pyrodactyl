<?php

namespace Pterodactyl\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Plan;
use Pterodactyl\Http\Resources\Admin\PlanResource;
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
}
