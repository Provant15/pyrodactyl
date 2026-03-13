<?php

namespace Pterodactyl\Services\Roles;

/**
 * Handles permission resolution, wildcard matching, and validation
 * against the canonical permission registry.
 */
class RolePermissionService
{
    /**
     * Canonical registry of all valid admin permission strings.
     * Wildcard patterns are NOT included here - they are resolved at check time.
     */
    private const PERMISSIONS = [
        'admin:servers.view', 'admin:servers.create', 'admin:servers.update',
        'admin:servers.delete', 'admin:servers.suspend', 'admin:servers.transfer',
        'admin:servers.reinstall',

        'admin:slots.view', 'admin:slots.create', 'admin:slots.update',
        'admin:slots.delete', 'admin:slots.deploy', 'admin:slots.archive',
        'admin:slots.restore',

        'admin:users.view', 'admin:users.create', 'admin:users.update',
        'admin:users.delete',

        'admin:nodes.view', 'admin:nodes.create', 'admin:nodes.update',
        'admin:nodes.delete',

        'admin:locations.view', 'admin:locations.create', 'admin:locations.update',
        'admin:locations.delete',

        'admin:eggs.view', 'admin:eggs.create', 'admin:eggs.update',
        'admin:eggs.delete', 'admin:eggs.import', 'admin:eggs.export',
        'admin:eggs.scripts',

        'admin:nests.view', 'admin:nests.create', 'admin:nests.update',
        'admin:nests.delete',

        'admin:database-hosts.view', 'admin:database-hosts.create',
        'admin:database-hosts.update', 'admin:database-hosts.delete',

        'admin:mounts.view', 'admin:mounts.create', 'admin:mounts.update',
        'admin:mounts.delete',

        'admin:plans.view', 'admin:plans.manage',

        'admin:roles.view', 'admin:roles.manage',

        'admin:settings.view', 'admin:settings.update',

        'admin:domains.view', 'admin:domains.create', 'admin:domains.update',
        'admin:domains.delete',

        'admin:audit.view',

        'admin:api-tokens.view', 'admin:api-tokens.manage',
    ];

    /**
     * Checks whether the given permission set grants access to the required permission.
     * Supports three wildcard patterns:
     *   - admin:*          (full wildcard - all resources, all actions)
     *   - admin:servers.*  (resource wildcard - all actions on one resource)
     *   - admin:*.view     (action wildcard - one action across all resources)
     *
     * @param array<string> $userPermissions The user's granted permissions
     * @param string $required The permission being checked
     */
    public function hasPermission(array $userPermissions, string $required): bool
    {
        if (empty($userPermissions)) {
            return false;
        }

        // Extract the resource.action part from the required permission
        $requiredParts = $this->parseParts($required);
        if ($requiredParts === null) {
            return false;
        }

        foreach ($userPermissions as $granted) {
            $grantedParts = $this->parseParts($granted);
            if ($grantedParts === null) {
                continue;
            }

            // Full wildcard: admin:*
            if ($grantedParts['resource'] === '*' && $grantedParts['action'] === null) {
                return true;
            }

            // Resource wildcard: admin:servers.*
            if ($grantedParts['action'] === '*' && $grantedParts['resource'] === $requiredParts['resource']) {
                return true;
            }

            // Action wildcard: admin:*.view
            if ($grantedParts['resource'] === '*' && $grantedParts['action'] === $requiredParts['action']) {
                return true;
            }

            // Exact match
            if ($grantedParts['resource'] === $requiredParts['resource']
                && $grantedParts['action'] === $requiredParts['action']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates an array of permission strings against the canonical registry.
     * Returns the strings that are invalid (not in registry and not valid wildcards).
     *
     * @param array<string> $permissions
     * @return array<string> Invalid permission strings
     */
    public function validatePermissions(array $permissions): array
    {
        $invalid = [];

        foreach ($permissions as $perm) {
            if (!$this->isValidPermission($perm)) {
                $invalid[] = $perm;
            }
        }

        return $invalid;
    }

    /**
     * Returns the complete list of canonical (non-wildcard) permissions.
     *
     * @return array<string>
     */
    public function getAllPermissions(): array
    {
        return self::PERMISSIONS;
    }

    /**
     * Checks if a permission string is valid (either canonical or a valid wildcard pattern).
     */
    private function isValidPermission(string $permission): bool
    {
        // Exact canonical match
        if (in_array($permission, self::PERMISSIONS, true)) {
            return true;
        }

        // Valid wildcard patterns
        if ($permission === 'admin:*') {
            return true;
        }

        $parts = $this->parseParts($permission);
        if ($parts === null) {
            return false;
        }

        // Resource wildcard: admin:servers.* - resource must be valid
        if ($parts['action'] === '*') {
            $resources = $this->getResources();
            return in_array($parts['resource'], $resources, true);
        }

        // Action wildcard: admin:*.view - action must be valid
        if ($parts['resource'] === '*') {
            $actions = $this->getActions();
            return in_array($parts['action'], $actions, true);
        }

        return false;
    }

    /**
     * Parses a permission string into its resource and action parts.
     * Returns null if the format is invalid.
     *
     * @return array{resource: string, action: string|null}|null
     */
    private function parseParts(string $permission): ?array
    {
        if (!str_starts_with($permission, 'admin:')) {
            return null;
        }

        $body = substr($permission, 6); // strip "admin:"

        // Full wildcard
        if ($body === '*') {
            return ['resource' => '*', 'action' => null];
        }

        $dotPos = strpos($body, '.');
        if ($dotPos === false) {
            return null;
        }

        return [
            'resource' => substr($body, 0, $dotPos),
            'action' => substr($body, $dotPos + 1),
        ];
    }

    /**
     * Extracts the unique set of resource names from the canonical permissions.
     *
     * @return array<string>
     */
    private function getResources(): array
    {
        $resources = [];
        foreach (self::PERMISSIONS as $perm) {
            $parts = $this->parseParts($perm);
            if ($parts !== null) {
                $resources[] = $parts['resource'];
            }
        }

        return array_values(array_unique($resources));
    }

    /**
     * Extracts the unique set of action names from the canonical permissions.
     *
     * @return array<string>
     */
    private function getActions(): array
    {
        $actions = [];
        foreach (self::PERMISSIONS as $perm) {
            $parts = $this->parseParts($perm);
            if ($parts !== null && $parts['action'] !== null) {
                $actions[] = $parts['action'];
            }
        }

        return array_values(array_unique($actions));
    }
}
