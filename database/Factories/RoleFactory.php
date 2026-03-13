<?php

namespace Database\Factories;

use Pterodactyl\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Role::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => 'Role_' . $this->faker->unique()->word(),
            'description' => $this->faker->sentence(),
            'is_default' => false,
            'is_system' => false,
        ];
    }

    /**
     * Mark this role as a system role (cannot be deleted).
     */
    public function system(): self
    {
        return $this->state(['is_system' => true]);
    }

    /**
     * Mark this role as the default role for new users.
     */
    public function default(): self
    {
        return $this->state(['is_default' => true]);
    }
}
