<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Pterodactyl\Models\Role;
use Pterodactyl\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    public function testRoleCanBeCreatedWithValidAttributes(): void
    {
        $role = Role::query()->create([
            'name' => 'Test Role',
            'description' => 'A test role',
            'is_default' => false,
            'is_system' => false,
        ]);

        $this->assertDatabaseHas('roles', ['name' => 'Test Role']);
        $this->assertInstanceOf(Role::class, $role);
    }

    public function testRoleHasPermissionsRelation(): void
    {
        $role = Role::query()->create([
            'name' => 'Test Role',
        ]);

        $role->permissions()->create(['permission' => 'admin:servers.view']);

        $this->assertCount(1, $role->permissions);
        $this->assertEquals('admin:servers.view', $role->permissions->first()->permission);
    }

    public function testRoleHasUsersRelation(): void
    {
        $role = Role::query()->create(['name' => 'Test Role']);
        $user = User::factory()->create();

        $role->users()->attach($user->id);

        $this->assertCount(1, $role->users);
        $this->assertEquals($user->id, $role->users->first()->id);
    }
}
