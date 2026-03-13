<?php

namespace Tests\Unit\Http\Resources\Admin;

use Tests\TestCase;
use Pterodactyl\Models\Role;
use Pterodactyl\Http\Resources\Admin\RoleResource;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RoleResourceTest extends TestCase
{
    use RefreshDatabase;

    public function testTransformsRoleToArray(): void
    {
        $role = Role::query()->create(['name' => 'Test', 'description' => 'A test role']);
        $role->permissions()->create(['permission' => 'admin:servers.view']);
        $role->loadCount('users')->load('permissions');

        $resource = (new RoleResource($role))->resolve();

        $this->assertEquals('Test', $resource['name']);
        $this->assertEquals('A test role', $resource['description']);
        $this->assertContains('admin:servers.view', $resource['permissions']);
        $this->assertEquals(0, $resource['users_count']);
    }

    public function testOmitsPermissionsWhenNotLoaded(): void
    {
        $role = Role::query()->create(['name' => 'Bare']);

        $resource = (new RoleResource($role))->resolve();

        $this->assertArrayNotHasKey('permissions', $resource);
    }
}
