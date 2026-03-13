<?php

namespace Database\Factories;

use Pterodactyl\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Plan::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => 'Plan_' . $this->faker->unique()->word(),
            'description' => $this->faker->sentence(),
            'memory' => $this->faker->randomElement([1024, 2048, 4096, 8192]),
            'disk' => $this->faker->randomElement([10240, 20480, 51200]),
            'cpu' => $this->faker->randomElement([100, 200, 400]),
            'io' => 500,
            'swap' => 0,
            'oom_disabled' => false,
            'databases_limit' => 2,
            'backups_limit' => 3,
            'allocations_limit' => 1,
            'archive_limit' => 3,
            'is_default' => false,
        ];
    }

    /**
     * Mark this plan as the default.
     */
    public function default(): self
    {
        return $this->state(['is_default' => true]);
    }
}
