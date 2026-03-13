<?php

namespace Database\Factories;

use Pterodactyl\Models\Plan;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ServerSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerSlotFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ServerSlot::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'node_id' => function () {
                $location = Location::factory()->create();

                return Node::factory()->create(['location_id' => $location->id])->id;
            },
            'allocation_id' => function (array $attributes) {
                return Allocation::factory()->create(['node_id' => $attributes['node_id']])->id;
            },
            'status' => ServerSlot::STATUS_IDLE,
        ];
    }

    /**
     * Set the slot to deploying status.
     */
    public function deploying(): self
    {
        return $this->state(['status' => ServerSlot::STATUS_DEPLOYING]);
    }

    /**
     * Set the slot to archiving status.
     */
    public function archiving(): self
    {
        return $this->state(['status' => ServerSlot::STATUS_ARCHIVING]);
    }

    /**
     * Set the slot to restoring status.
     */
    public function restoring(): self
    {
        return $this->state(['status' => ServerSlot::STATUS_RESTORING]);
    }
}
