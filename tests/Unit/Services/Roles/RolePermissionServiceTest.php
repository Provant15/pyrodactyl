<?php

namespace Tests\Unit\Services\Roles;

use Tests\TestCase;
use Pterodactyl\Services\Roles\RolePermissionService;

class RolePermissionServiceTest extends TestCase
{
    private RolePermissionService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new RolePermissionService();
    }

    public function testExactPermissionMatch(): void
    {
        $permissions = ['admin:servers.view', 'admin:servers.create'];
        $this->assertTrue($this->service->hasPermission($permissions, 'admin:servers.view'));
        $this->assertFalse($this->service->hasPermission($permissions, 'admin:servers.delete'));
    }

    public function testFullWildcardMatchesEverything(): void
    {
        $permissions = ['admin:*'];
        $this->assertTrue($this->service->hasPermission($permissions, 'admin:servers.view'));
        $this->assertTrue($this->service->hasPermission($permissions, 'admin:users.create'));
        $this->assertTrue($this->service->hasPermission($permissions, 'admin:roles.manage'));
    }

    public function testResourceWildcardMatchesAllActionsOnResource(): void
    {
        $permissions = ['admin:servers.*'];
        $this->assertTrue($this->service->hasPermission($permissions, 'admin:servers.view'));
        $this->assertTrue($this->service->hasPermission($permissions, 'admin:servers.delete'));
        $this->assertFalse($this->service->hasPermission($permissions, 'admin:users.view'));
    }

    public function testActionWildcardMatchesActionAcrossResources(): void
    {
        $permissions = ['admin:*.view'];
        $this->assertTrue($this->service->hasPermission($permissions, 'admin:servers.view'));
        $this->assertTrue($this->service->hasPermission($permissions, 'admin:users.view'));
        $this->assertFalse($this->service->hasPermission($permissions, 'admin:users.create'));
    }

    public function testValidatePermissionsAcceptsValidStrings(): void
    {
        $valid = ['admin:servers.view', 'admin:users.create', 'admin:*.view'];
        $this->assertEmpty($this->service->validatePermissions($valid));
    }

    public function testValidatePermissionsRejectsInvalidStrings(): void
    {
        $invalid = ['admin:servers.view', 'admin:invalid_resource.view', 'nonsense'];
        $rejected = $this->service->validatePermissions($invalid);
        $this->assertCount(2, $rejected);
        $this->assertContains('admin:invalid_resource.view', $rejected);
        $this->assertContains('nonsense', $rejected);
    }

    public function testGetAllPermissionsReturnsCanonicalList(): void
    {
        $all = $this->service->getAllPermissions();
        $this->assertContains('admin:servers.view', $all);
        $this->assertContains('admin:roles.manage', $all);
        $this->assertNotContains('admin:*', $all); // wildcards are not canonical permissions
    }

    public function testEmptyPermissionsMatchNothing(): void
    {
        $this->assertFalse($this->service->hasPermission([], 'admin:servers.view'));
    }
}
