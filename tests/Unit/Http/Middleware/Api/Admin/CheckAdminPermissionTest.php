<?php

namespace Pterodactyl\Tests\Unit\Http\Middleware\Api\Admin;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Models\Role;
use Pterodactyl\Models\User;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pterodactyl\Services\Roles\RolePermissionService;
use Pterodactyl\Http\Middleware\Api\Admin\CheckAdminPermission;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CheckAdminPermissionTest extends TestCase
{
    use RefreshDatabase;

    private CheckAdminPermission $middleware;

    public function setUp(): void
    {
        parent::setUp();
        $this->middleware = new CheckAdminPermission(new RolePermissionService());
    }

    public function testAllowsUserWithExactPermission(): void
    {
        $user = User::factory()->create(['root_admin' => false]);
        $role = Role::query()->create(['name' => 'Server Viewer']);
        $role->permissions()->create(['permission' => 'admin:servers.view']);
        $user->roles()->attach($role->id);

        $request = Request::create('/api/admin/servers');
        $request->setUserResolver(fn () => $user);

        $response = $this->middleware->handle($request, fn ($r) => 'passed', 'admin:servers.view');

        $this->assertEquals('passed', $response);
    }

    public function testAllowsRootAdminRegardlessOfRoles(): void
    {
        $user = User::factory()->create(['root_admin' => true]);

        $request = Request::create('/api/admin/servers');
        $request->setUserResolver(fn () => $user);

        $response = $this->middleware->handle($request, fn ($r) => 'passed', 'admin:servers.view');

        $this->assertEquals('passed', $response);
    }

    public function testDeniesUserWithoutRequiredPermission(): void
    {
        $user = User::factory()->create(['root_admin' => false]);
        $role = Role::query()->create(['name' => 'User Viewer']);
        $role->permissions()->create(['permission' => 'admin:users.view']);
        $user->roles()->attach($role->id);

        $request = Request::create('/api/admin/servers');
        $request->setUserResolver(fn () => $user);

        $this->expectException(AccessDeniedHttpException::class);

        $this->middleware->handle($request, fn ($r) => 'passed', 'admin:servers.view');
    }

    public function testWildcardPermissionGrantsAccess(): void
    {
        $user = User::factory()->create(['root_admin' => false]);
        $role = Role::query()->create(['name' => 'Full Admin']);
        $role->permissions()->create(['permission' => 'admin:*']);
        $user->roles()->attach($role->id);

        $request = Request::create('/api/admin/servers');
        $request->setUserResolver(fn () => $user);

        $response = $this->middleware->handle($request, fn ($r) => 'passed', 'admin:servers.delete');

        $this->assertEquals('passed', $response);
    }
}
