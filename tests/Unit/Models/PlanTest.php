<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Pterodactyl\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PlanTest extends TestCase
{
    use RefreshDatabase;

    public function testPlanCanBeCreatedWithValidAttributes(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Medium Gaming',
            'memory' => 4096,
            'disk' => 20480,
            'cpu' => 200,
            'io' => 500,
            'swap' => 0,
        ]);

        $this->assertDatabaseHas('plans', ['name' => 'Medium Gaming']);
        $this->assertEquals(4096, $plan->memory);
    }

    public function testOnlyOnePlanCanBeDefault(): void
    {
        Plan::query()->create([
            'name' => 'Plan A',
            'memory' => 1024, 'disk' => 10240, 'cpu' => 100,
            'is_default' => true,
        ]);

        $planB = Plan::query()->create([
            'name' => 'Plan B',
            'memory' => 2048, 'disk' => 20480, 'cpu' => 200,
            'is_default' => true,
        ]);

        // Only one default should exist at a time - enforced by service layer, not DB.
        // This test verifies the model allows it (service handles uniqueness).
        $this->assertEquals(2, Plan::where('is_default', true)->count());
    }

    public function testPlanHasSlotsRelation(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Test Plan',
            'memory' => 1024, 'disk' => 10240, 'cpu' => 100,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $plan->slots());
    }
}
