<?php

namespace Pterodactyl\Http\Middleware\Api\Admin;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Verifies that the authenticated user has any admin access (root_admin flag or at least one admin role).
 * This is the first gate - applied to the entire /api/admin/* route group.
 */
class AuthenticateAdminUser
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (!$user) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        if ($user->root_admin) {
            return $next($request);
        }

        if ($user->roles()->exists()) {
            return $next($request);
        }

        throw new AccessDeniedHttpException('This account does not have admin access.');
    }
}
