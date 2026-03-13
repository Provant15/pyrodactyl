<?php

use Pterodactyl\Models\Role;
use Pterodactyl\Models\User;
use Database\Seeders\TemplateRoleSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        // Ensure template roles exist
        (new TemplateRoleSeeder())->run();

        // Assign all root_admin users to the Full Administrator role
        $fullAdmin = Role::where('name', 'Full Administrator')->where('is_system', true)->first();

        if ($fullAdmin) {
            $adminUserIds = User::where('root_admin', true)->pluck('id');
            foreach ($adminUserIds as $userId) {
                // Use insertOrIgnore to handle any existing assignments
                \DB::table('role_user')->insertOrIgnore([
                    'user_id' => $userId,
                    'role_id' => $fullAdmin->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Remove all role_user assignments for system roles
        $systemRoleIds = Role::where('is_system', true)->pluck('id');
        \DB::table('role_user')->whereIn('role_id', $systemRoleIds)->delete();

        // Delete system roles and their permissions
        Role::where('is_system', true)->delete();
    }
};
