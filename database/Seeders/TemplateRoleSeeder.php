<?php

namespace Database\Seeders;

use Pterodactyl\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the template admin roles defined in the design spec.
 * Idempotent - safe to run multiple times. Existing system roles are skipped.
 */
class TemplateRoleSeeder extends Seeder
{
    private const ROLES = [
        'Full Administrator' => ['admin:*'],
        'Server Manager' => [
            'admin:servers.*', 'admin:slots.*',
            'admin:nodes.view', 'admin:eggs.view', 'admin:plans.view',
        ],
        'User Manager' => ['admin:users.*'],
        'Egg Developer' => ['admin:eggs.*', 'admin:nests.*'],
        'Read-Only Auditor' => ['admin:*.view', 'admin:audit.view'],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $name => $permissions) {
            $role = Role::firstOrCreate(
                ['name' => $name, 'is_system' => true],
                ['description' => "System template role: {$name}"],
            );

            // Only set permissions if the role was just created (not already existing)
            if ($role->wasRecentlyCreated) {
                foreach ($permissions as $perm) {
                    $role->permissions()->create(['permission' => $perm]);
                }
            }
        }
    }
}
