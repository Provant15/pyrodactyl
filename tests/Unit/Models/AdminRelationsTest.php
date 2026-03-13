<?php

namespace Pterodactyl\Tests\Unit\Models;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Models\Role;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AdminRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function testUserHasRolesRelation(): void
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'Test Role']);

        $user->roles()->attach($role->id);

        $this->assertCount(1, $user->roles);
        $this->assertEquals('Test Role', $user->roles->first()->name);
    }

    public function testUserAdminPermissionsReturnsUnionOfRolePermissions(): void
    {
        $user = User::factory()->create();

        $roleA = Role::query()->create(['name' => 'Role A']);
        $roleA->permissions()->create(['permission' => 'admin:servers.view']);
        $roleA->permissions()->create(['permission' => 'admin:servers.create']);

        $roleB = Role::query()->create(['name' => 'Role B']);
        $roleB->permissions()->create(['permission' => 'admin:users.view']);
        $roleB->permissions()->create(['permission' => 'admin:servers.view']); // duplicate

        $user->roles()->attach([$roleA->id, $roleB->id]);

        $permissions = $user->adminPermissions();

        $this->assertCount(3, $permissions);
        $this->assertContains('admin:servers.view', $permissions);
        $this->assertContains('admin:servers.create', $permissions);
        $this->assertContains('admin:users.view', $permissions);
    }

    public function testServerHasNewStatusConstants(): void
    {
        $this->assertEquals('archived', Server::STATUS_ARCHIVED);
        $this->assertEquals('archiving', Server::STATUS_ARCHIVING);
        $this->assertEquals('restoring', Server::STATUS_RESTORING);
    }
}
