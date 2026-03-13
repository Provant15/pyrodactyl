<?php

namespace Pterodactyl\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Role;
use Pterodactyl\Http\Resources\Admin\RoleResource;
use Pterodactyl\Services\Roles\RoleCreationService;
use Pterodactyl\Services\Roles\RoleUpdateService;
use Pterodactyl\Services\Roles\RolePermissionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Handles CRUD operations for admin roles and the canonical permission list.
 */
class RoleController extends AdminApiController
{
    public function __construct(
        private RoleCreationService $creationService,
        private RoleUpdateService $updateService,
        private RolePermissionService $permissionService,
    ) {
    }

    /**
     * Lists all roles with permission and user counts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $roles = Role::query()
            ->withCount('users')
            ->with('permissions')
            ->orderBy('name')
            ->paginate(min($request->query('per_page', 25), 100));

        return RoleResource::collection($roles);
    }

    /**
     * Shows a single role with its permissions.
     */
    public function show(int $role): RoleResource
    {
        $role = Role::query()
            ->withCount('users')
            ->with('permissions')
            ->findOrFail($role);

        return new RoleResource($role);
    }

    /**
     * Creates a new role with permissions.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'description' => 'nullable|string',
            'permissions' => 'present|array',
            'permissions.*' => 'string',
        ]);

        $role = $this->creationService->handle($validated);

        return $this->returnCreated(new RoleResource($role));
    }

    /**
     * Updates an existing role and replaces its permissions.
     */
    public function update(Request $request, int $role): RoleResource
    {
        $role = Role::findOrFail($role);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:191',
            'description' => 'nullable|string',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string',
        ]);

        $role = $this->updateService->handle($role, $validated);

        return new RoleResource($role);
    }

    /**
     * Deletes a role (system roles cannot be deleted).
     */
    public function destroy(int $role): JsonResponse
    {
        $role = Role::findOrFail($role);

        if ($role->is_system) {
            abort(422, 'System roles cannot be deleted.');
        }

        $role->delete();

        return $this->returnNoContent();
    }

    /**
     * Returns the canonical list of all valid permission strings.
     */
    public function permissions(): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->permissionService->getAllPermissions(),
        ]);
    }
}
