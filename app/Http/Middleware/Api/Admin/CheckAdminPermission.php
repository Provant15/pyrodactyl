<?php

namespace Pterodactyl\Http\Middleware\Api\Admin;

use Closure;
use Illuminate\Http\Request;
use Pterodactyl\Services\Roles\RolePermissionService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Checks whether the authenticated user has the specific admin permission required
 * by the current route. Applied per-route via middleware parameters.
 *
 * Usage in routes: ->middleware('admin.permission:admin:servers.view')
 */
class CheckAdminPermission
{
    public function __construct(
        private RolePermissionService $permissionService,
    ) {
    }

    /**
     * @param string $permission The required permission string (passed as middleware parameter)
     */
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        $user = $request->user();

        // root_admin bypasses all permission checks
        if ($user->root_admin) {
            return $next($request);
        }

        $userPermissions = $user->adminPermissions();

        if ($this->permissionService->hasPermission($userPermissions, $permission)) {
            return $next($request);
        }

        throw new AccessDeniedHttpException(
            "This account does not have the required permission: {$permission}"
        );
    }
}
