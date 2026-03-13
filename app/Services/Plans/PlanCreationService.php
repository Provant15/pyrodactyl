<?php

namespace Pterodactyl\Services\Plans;

use Pterodactyl\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Handles creating a new resource plan. Ensures only one plan is marked as default.
 */
class PlanCreationService
{
    /**
     * Creates a new plan with the given attributes.
     *
     * @param array{name: string, memory: int, disk: int, cpu: int, ...} $data
     */
    public function handle(array $data): Plan
    {
        return DB::transaction(function () use ($data) {
            if (!empty($data['is_default'])) {
                Plan::where('is_default', true)->update(['is_default' => false]);
            }

            return Plan::query()->create($data);
        });
    }
}
