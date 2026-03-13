<?php

namespace Pterodactyl\Tests\Unit\Services\Roles;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Models\Role;
use Pterodactyl\Services\Roles\RoleCreationService;
use Pterodactyl\Services\Roles\RolePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RoleCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private RoleCreationService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new RoleCreationService(
            new RolePermissionService(),
        );
    }

    public function testCreatesRoleWithPermissions(): void
    {
        $role = $this->service->handle([
            'name' => 'Server Manager',
            'description' => 'Manages servers',
            'permissions' => ['admin:servers.view', 'admin:servers.create'],
        ]);

        $this->assertInstanceOf(Role::class, $role);
        $this->assertEquals('Server Manager', $role->name);
        $this->assertCount(2, $role->permissions);
    }

    public function testRejectsInvalidPermissions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->handle([
            'name' => 'Bad Role',
            'permissions' => ['admin:servers.view', 'admin:invalid.view'],
        ]);
    }

    public function testCreatesRoleWithNoPermissions(): void
    {
        $role = $this->service->handle([
            'name' => 'Empty Role',
            'permissions' => [],
        ]);

        $this->assertCount(0, $role->permissions);
    }
}
