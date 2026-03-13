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

            return Plan::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'memory' => $data['memory'],
                'disk' => $data['disk'],
                'cpu' => $data['cpu'],
                'io' => $data['io'] ?? 500,
                'swap' => $data['swap'] ?? 0,
                'oom_disabled' => $data['oom_disabled'] ?? false,
                'threads' => $data['threads'] ?? null,
                'databases_limit' => $data['databases_limit'] ?? 0,
                'backups_limit' => $data['backups_limit'] ?? 0,
                'allocations_limit' => $data['allocations_limit'] ?? 0,
                'archive_limit' => $data['archive_limit'] ?? 0,
                'is_default' => $data['is_default'] ?? false,
            ]);
        });
    }
}
