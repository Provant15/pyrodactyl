<?php

namespace Tests\Unit\Services\Plans;

use Tests\TestCase;
use Pterodactyl\Models\Plan;
use Pterodactyl\Services\Plans\PlanCreationService;
use Pterodactyl\Services\Plans\PlanUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PlanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function testCreatesAPlan(): void
    {
        $service = new PlanCreationService();
        $plan = $service->handle([
            'name' => 'Medium Gaming',
            'memory' => 4096,
            'disk' => 20480,
            'cpu' => 200,
        ]);

        $this->assertInstanceOf(Plan::class, $plan);
        $this->assertEquals('Medium Gaming', $plan->name);
        $this->assertEquals(4096, $plan->memory);
    }

    public function testSettingDefaultPlanClearsPreviousDefault(): void
    {
        $service = new PlanCreationService();

        $planA = $service->handle([
            'name' => 'Plan A', 'memory' => 1024, 'disk' => 10240, 'cpu' => 100,
            'is_default' => true,
        ]);

        $planB = $service->handle([
            'name' => 'Plan B', 'memory' => 2048, 'disk' => 20480, 'cpu' => 200,
            'is_default' => true,
        ]);

        $this->assertFalse($planA->fresh()->is_default);
        $this->assertTrue($planB->is_default);
    }

    public function testUpdatesAPlan(): void
    {
        $creation = new PlanCreationService();
        $plan = $creation->handle([
            'name' => 'Old Name', 'memory' => 1024, 'disk' => 10240, 'cpu' => 100,
        ]);

        $update = new PlanUpdateService();
        $updated = $update->handle($plan, ['name' => 'New Name', 'memory' => 2048]);

        $this->assertEquals('New Name', $updated->name);
        $this->assertEquals(2048, $updated->memory);
    }
}
