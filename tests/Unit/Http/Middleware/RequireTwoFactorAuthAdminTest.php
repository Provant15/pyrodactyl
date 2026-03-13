<?php

namespace Tests\Unit\Http\Middleware;

use Tests\TestCase;
use Pterodactyl\Models\Role;
use Pterodactyl\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RequireTwoFactorAuthAdminTest extends TestCase
{
    use RefreshDatabase;

    public function testRoleBasedAdminIsSubjectToAdminTwoFactorPolicy(): void
    {
        // Set admin-level 2FA required (LEVEL_ADMIN = 1)
        config(['pterodactyl.auth.2fa_required' => 1]);

        $user = User::factory()->create(['root_admin' => false, 'use_totp' => false]);
        $role = Role::query()->create(['name' => 'Test Admin']);
        $user->roles()->attach($role->id);

        // This user should be subject to admin 2FA since they have an admin role
        $response = $this->actingAs($user)->getJson('/api/admin/roles');

        // Admin API routes start with /api/, so the middleware throws
        // TwoFactorAuthRequiredException (400) rather than redirecting (302)
        $response->assertStatus(400);
    }
}
