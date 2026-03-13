<?php

namespace Pterodactyl\Services\Plans;

use Pterodactyl\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Handles updating an existing resource plan.
 */
class PlanUpdateService
{
    /**
     * Updates the given plan with new attributes.
     *
     * @param array<string, mixed> $data
     */
    public function handle(Plan $plan, array $data): Plan
    {
        return DB::transaction(function () use ($plan, $data) {
            if (!empty($data['is_default'])) {
                Plan::where('is_default', true)
                    ->where('id', '!=', $plan->id)
                    ->update(['is_default' => false]);
            }

            $plan->update($data);

            return $plan->fresh();
        });
    }
}
