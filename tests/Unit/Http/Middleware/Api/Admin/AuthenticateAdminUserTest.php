<?php

namespace Pterodactyl\Tests\Unit\Http\Middleware\Api\Admin;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Models\Role;
use Pterodactyl\Models\User;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pterodactyl\Http\Middleware\Api\Admin\AuthenticateAdminUser;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AuthenticateAdminUserTest extends TestCase
{
    use RefreshDatabase;

    private AuthenticateAdminUser $middleware;

    public function setUp(): void
    {
        parent::setUp();
        $this->middleware = new AuthenticateAdminUser();
    }

    public function testAllowsRootAdmin(): void
    {
        $user = User::factory()->create(['root_admin' => true]);
        $request = Request::create('/api/admin/test');
        $request->setUserResolver(fn () => $user);

        $response = $this->middleware->handle($request, fn ($r) => 'passed');

        $this->assertEquals('passed', $response);
    }

    public function testAllowsUserWithAdminRole(): void
    {
        $user = User::factory()->create(['root_admin' => false]);
        $role = Role::query()->create(['name' => 'Test Admin']);
        $user->roles()->attach($role->id);

        $request = Request::create('/api/admin/test');
        $request->setUserResolver(fn () => $user);

        $response = $this->middleware->handle($request, fn ($r) => 'passed');

        $this->assertEquals('passed', $response);
    }

    public function testDeniesUserWithNoAdminAccess(): void
    {
        $user = User::factory()->create(['root_admin' => false]);

        $request = Request::create('/api/admin/test');
        $request->setUserResolver(fn () => $user);

        $this->expectException(AccessDeniedHttpException::class);

        $this->middleware->handle($request, fn ($r) => 'passed');
    }
}
