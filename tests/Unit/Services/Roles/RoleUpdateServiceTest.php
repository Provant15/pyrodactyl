<?php

namespace Tests\Unit\Services\Roles;

use Tests\TestCase;
use Pterodactyl\Models\Role;
use Pterodactyl\Services\Roles\RoleUpdateService;
use Pterodactyl\Services\Roles\RolePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RoleUpdateServiceTest extends TestCase
{
    use RefreshDatabase;

    private RoleUpdateService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new RoleUpdateService(
            new RolePermissionService(),
        );
    }

    public function testUpdatesRoleAttributes(): void
    {
        $role = Role::query()->create(['name' => 'Old Name', 'description' => 'Old']);

        $updated = $this->service->handle($role, [
            'name' => 'New Name',
            'description' => 'New description',
        ]);

        $this->assertEquals('New Name', $updated->name);
        $this->assertEquals('New description', $updated->description);
    }

    public function testReplacesPermissions(): void
    {
        $role = Role::query()->create(['name' => 'Test']);
        $role->permissions()->create(['permission' => 'admin:servers.view']);

        $updated = $this->service->handle($role, [
            'permissions' => ['admin:users.view', 'admin:users.update'],
        ]);

        $this->assertCount(2, $updated->permissions);
        $perms = $updated->permissions->pluck('permission')->all();
        $this->assertContains('admin:users.view', $perms);
        $this->assertContains('admin:users.update', $perms);
    }

    public function testRejectsInvalidPermissions(): void
    {
        $role = Role::query()->create(['name' => 'Test']);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->handle($role, [
            'permissions' => ['admin:fake.permission'],
        ]);
    }

    public function testUpdatesAttributesAndPermissionsAtomically(): void
    {
        $role = Role::query()->create(['name' => 'Old']);
        $role->permissions()->create(['permission' => 'admin:servers.view']);

        $updated = $this->service->handle($role, [
            'name' => 'New',
            'permissions' => ['admin:users.view'],
        ]);

        $this->assertEquals('New', $updated->name);
        $this->assertCount(1, $updated->permissions);
        $this->assertEquals('admin:users.view', $updated->permissions->first()->permission);
    }
}
