<?php

namespace Tests\Integration\Api\Admin;

use Tests\TestCase;
use Pterodactyl\Models\Role;
use Pterodactyl\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RoleControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    public function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['root_admin' => true]);
    }

    public function testListRoles(): void
    {
        Role::query()->create(['name' => 'Role A']);
        Role::query()->create(['name' => 'Role B']);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/roles');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Role A');
    }

    public function testCreateRole(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/roles', [
            'name' => 'Server Manager',
            'description' => 'Manages servers',
            'permissions' => ['admin:servers.view', 'admin:servers.create'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Server Manager');
        $response->assertJsonCount(2, 'data.permissions');
        $this->assertDatabaseHas('roles', ['name' => 'Server Manager']);
    }

    public function testShowRole(): void
    {
        $role = Role::query()->create(['name' => 'Test Role']);
        $role->permissions()->create(['permission' => 'admin:servers.view']);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/roles/{$role->id}");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Test Role');
        $response->assertJsonPath('data.permissions.0', 'admin:servers.view');
    }

    public function testUpdateRole(): void
    {
        $role = Role::query()->create(['name' => 'Old Name']);

        $response = $this->actingAs($this->admin)->patchJson("/api/admin/roles/{$role->id}", [
            'name' => 'New Name',
            'permissions' => ['admin:users.view'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('roles', ['name' => 'New Name']);
    }

    public function testDeleteRole(): void
    {
        $role = Role::query()->create(['name' => 'Deletable']);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/roles/{$role->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function testCannotDeleteSystemRole(): void
    {
        $role = Role::query()->create(['name' => 'System Role', 'is_system' => true]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/roles/{$role->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function testNonAdminCannotAccessRoles(): void
    {
        $user = User::factory()->create(['root_admin' => false]);

        $response = $this->actingAs($user)->getJson('/api/admin/roles');

        $response->assertForbidden();
    }

    public function testPermissionsEndpointReturnsCanonicalList(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/permissions');

        $response->assertOk();
        $response->assertJsonStructure(['data']);
        $data = $response->json('data');
        $this->assertContains('admin:servers.view', $data);
    }
}
