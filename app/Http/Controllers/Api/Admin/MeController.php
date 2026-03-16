<?php

namespace Pterodactyl\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the authenticated admin user's profile and resolved permissions.
 * Used by the odin admin panel to populate its auth store on init.
 */
class MeController extends AdminApiController
{
    /**
     * Return the current admin user's profile and permissions.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $permissions = $user->roles
            ->flatMap(fn ($role) => $role->permissions->pluck('permission'))
            ->unique()
            ->values()
            ->all();

        return new JsonResponse([
            'data' => [
                'id' => $user->id,
                'uuid' => $user->uuid,
                'username' => $user->username,
                'email' => $user->email,
                'name_first' => $user->name_first,
                'name_last' => $user->name_last,
                'root_admin' => $user->root_admin,
                'admin_permissions' => $permissions,
                'created_at' => $user->created_at->toIso8601String(),
            ],
        ]);
    }
}
