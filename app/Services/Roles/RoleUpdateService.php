<?php

namespace Pterodactyl\Services\Roles;

use Pterodactyl\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Handles updating a role's attributes and replacing its permissions atomically.
 */
class RoleUpdateService
{
    public function __construct(
        private RolePermissionService $permissionService,
    ) {
    }

    /**
     * Updates the given role's attributes and/or replaces its permissions.
     *
     * @param Role $role The role to update
     * @param array{name?: string, description?: string, permissions?: array<string>} $data
     * @return Role The updated role with permissions and user count loaded
     * @throws \InvalidArgumentException If any permission strings are invalid
     */
    public function handle(Role $role, array $data): Role
    {
        $permissions = $data['permissions'] ?? null;
        if ($permissions !== null) {
            $permissions = array_values(array_unique($permissions));
        }

        if ($permissions !== null && !empty($permissions)) {
            $invalid = $this->permissionService->validatePermissions($permissions);
            if (!empty($invalid)) {
                throw new \InvalidArgumentException(
                    'Invalid permission strings: ' . implode(', ', $invalid)
                );
            }
        }

        return DB::transaction(function () use ($role, $data, $permissions) {
            $attributes = [];
            if (array_key_exists('name', $data) && $data['name'] !== null) {
                $attributes['name'] = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $attributes['description'] = $data['description']; // allow null to clear
            }

            if (!empty($attributes)) {
                $role->update($attributes);
            }

            if ($permissions !== null) {
                $role->permissions()->delete();
                foreach ($permissions as $perm) {
                    $role->permissions()->create(['permission' => $perm]);
                }
            }

            return $role->load('permissions')->loadCount('users');
        });
    }
}
