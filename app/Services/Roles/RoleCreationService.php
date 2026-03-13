<?php

namespace Pterodactyl\Services\Roles;

use Pterodactyl\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Handles creating a new admin role with validated permissions.
 */
class RoleCreationService
{
    public function __construct(
        private RolePermissionService $permissionService,
    ) {
    }

    /**
     * Creates a new role with the given attributes and permission strings.
     *
     * @param array{name: string, description?: string, permissions: array<string>} $data
     * @throws \InvalidArgumentException If any permission strings are invalid
     */
    public function handle(array $data): Role
    {
        $permissions = $data['permissions'] ?? [];

        if (!empty($permissions)) {
            $invalid = $this->permissionService->validatePermissions($permissions);
            if (!empty($invalid)) {
                throw new \InvalidArgumentException(
                    'Invalid permission strings: ' . implode(', ', $invalid)
                );
            }
        }

        return DB::transaction(function () use ($data, $permissions) {
            $role = Role::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_default' => $data['is_default'] ?? false,
                'is_system' => $data['is_system'] ?? false,
            ]);

            foreach ($permissions as $perm) {
                $role->permissions()->create(['permission' => $perm]);
            }

            return $role->load('permissions');
        });
    }
}
