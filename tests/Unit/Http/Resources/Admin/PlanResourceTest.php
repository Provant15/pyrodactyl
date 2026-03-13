<?php

namespace Tests\Unit\Http\Resources\Admin;

use Tests\TestCase;
use Pterodactyl\Models\Plan;
use Pterodactyl\Http\Resources\Admin\PlanResource;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PlanResourceTest extends TestCase
{
    use RefreshDatabase;

    public function testTransformsPlanToArray(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Basic',
            'memory' => 1024,
            'disk' => 10240,
            'cpu' => 100,
            'io' => 500,
            'swap' => 0,
            'oom_disabled' => false,
            'databases_limit' => 2,
            'backups_limit' => 3,
            'allocations_limit' => 1,
            'archive_limit' => 5,
        ]);

        $resource = (new PlanResource($plan))->resolve();

        $this->assertEquals('Basic', $resource['name']);
        $this->assertEquals(1024, $resource['resources']['memory']);
        $this->assertEquals(10240, $resource['resources']['disk']);
        $this->assertEquals(100, $resource['resources']['cpu']);
        $this->assertEquals(2, $resource['limits']['databases']);
        $this->assertEquals(3, $resource['limits']['backups']);
        $this->assertEquals(5, $resource['limits']['archives']);
    }
}
