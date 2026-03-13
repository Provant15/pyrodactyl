<?php

namespace Pterodactyl\Tests\Unit\Services\Slots;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Plan;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Services\Slots\SlotResourceService;
use Pterodactyl\Tests\TestCase;

class SlotResourceServiceTest extends TestCase
{
    private SlotResourceService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new SlotResourceService();
    }

    public function test_resolve_returns_plan_defaults_when_no_overrides(): void
    {
        $plan = Plan::factory()->make([
            'memory' => 4096,
            'disk' => 20480,
            'cpu' => 200,
            'io' => 500,
            'swap' => 512,
            'databases_limit' => 2,
            'backups_limit' => 3,
            'allocations_limit' => 1,
        ]);

        $slot = ServerSlot::factory()->make([
            'memory_override' => null,
            'disk_override' => null,
            'cpu_override' => null,
            'io_override' => null,
            'swap_override' => null,
        ]);
        $slot->setRelation('plan', $plan);

        $resources = $this->service->resolve($slot);

        $this->assertEquals(4096, $resources['memory']);
        $this->assertEquals(20480, $resources['disk']);
        $this->assertEquals(200, $resources['cpu']);
        $this->assertEquals(500, $resources['io']);
        $this->assertEquals(512, $resources['swap']);
        $this->assertEquals(2, $resources['databases_limit']);
        $this->assertEquals(3, $resources['backups_limit']);
        $this->assertEquals(1, $resources['allocations_limit']);
    }

    public function test_resolve_applies_overrides_over_plan_defaults(): void
    {
        $plan = Plan::factory()->make([
            'memory' => 4096, 'disk' => 20480, 'cpu' => 200,
            'io' => 500, 'swap' => 512,
        ]);

        $slot = ServerSlot::factory()->make([
            'memory_override' => 8192,
            'disk_override' => null,
            'cpu_override' => 400,
            'io_override' => null,
            'swap_override' => 0,
        ]);
        $slot->setRelation('plan', $plan);

        $resources = $this->service->resolve($slot);

        $this->assertEquals(8192, $resources['memory']);
        $this->assertEquals(20480, $resources['disk']);
        $this->assertEquals(400, $resources['cpu']);
        $this->assertEquals(500, $resources['io']);
        $this->assertEquals(0, $resources['swap']);
    }

    public function test_validate_for_egg_returns_warnings_when_below_minimums(): void
    {
        $egg = Egg::factory()->make([
            'name' => 'Minecraft',
            'min_memory' => 4096,
            'min_disk' => 10240,
            'min_cpu' => null,
        ]);

        $resources = ['memory' => 2048, 'disk' => 5120, 'cpu' => 100];
        $warnings = $this->service->validateForEgg($resources, $egg);

        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('2048', $warnings[0]);
        $this->assertStringContainsString('4096', $warnings[0]);
    }

    public function test_validate_for_egg_passes_when_resources_meet_minimums(): void
    {
        $egg = Egg::factory()->make([
            'min_memory' => 2048, 'min_disk' => 5120, 'min_cpu' => 100,
        ]);

        $resources = ['memory' => 4096, 'disk' => 20480, 'cpu' => 200];
        $warnings = $this->service->validateForEgg($resources, $egg);

        $this->assertEmpty($warnings);
    }
}
