<?php

namespace Pterodactyl\Tests\Unit\Database;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Models\Role;
use Database\Seeders\TemplateRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TemplateRoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function testSeedsAllTemplateRoles(): void
    {
        $this->seed(TemplateRoleSeeder::class);

        $this->assertDatabaseHas('roles', ['name' => 'Full Administrator', 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['name' => 'Server Manager', 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['name' => 'User Manager', 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['name' => 'Egg Developer', 'is_system' => true]);
        $this->assertDatabaseHas('roles', ['name' => 'Read-Only Auditor', 'is_system' => true]);
    }

    public function testFullAdminHasWildcardPermission(): void
    {
        $this->seed(TemplateRoleSeeder::class);

        $role = Role::where('name', 'Full Administrator')->first();
        $permissions = $role->permissions->pluck('permission')->all();

        $this->assertContains('admin:*', $permissions);
    }

    public function testSeederIsIdempotent(): void
    {
        $this->seed(TemplateRoleSeeder::class);
        $this->seed(TemplateRoleSeeder::class);

        $this->assertEquals(5, Role::where('is_system', true)->count());
    }
}
