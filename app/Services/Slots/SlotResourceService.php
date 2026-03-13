<?php

namespace Pterodactyl\Services\Slots;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\ServerSlot;

/**
 * Resolves effective resources for a server slot by applying per-slot
 * overrides over plan defaults, and validates resources against egg minimums.
 */
class SlotResourceService
{
    /**
     * Resolve effective resources for a slot.
     *
     * Per-slot overrides take precedence over plan defaults. Limits (databases,
     * backups, allocations) come from the plan directly with no per-slot override.
     *
     * @param ServerSlot $slot The slot with its plan relationship loaded
     * @return array{memory: int, disk: int, cpu: int, io: int, swap: int, databases_limit: int, backups_limit: int, allocations_limit: int}
     */
    public function resolve(ServerSlot $slot): array
    {
        $plan = $slot->plan;

        return [
            'memory' => $slot->memory_override ?? $plan->memory,
            'disk' => $slot->disk_override ?? $plan->disk,
            'cpu' => $slot->cpu_override ?? $plan->cpu,
            'io' => $slot->io_override ?? $plan->io,
            'swap' => $slot->swap_override ?? $plan->swap,
            'databases_limit' => $plan->databases_limit,
            'backups_limit' => $plan->backups_limit,
            'allocations_limit' => $plan->allocations_limit,
        ];
    }

    /**
     * Validate resolved resources against an egg's minimum requirements.
     *
     * @param array{memory: int, disk: int, cpu: int} $resources Resolved resources
     * @param Egg $egg The egg to validate against
     * @return string[] Warning messages for each failed check. Empty means all pass.
     */
    public function validateForEgg(array $resources, Egg $egg): array
    {
        $warnings = [];

        if ($egg->min_memory && $resources['memory'] < $egg->min_memory) {
            $warnings[] = "Plan provides {$resources['memory']}MB memory but {$egg->name} requires at least {$egg->min_memory}MB";
        }

        if ($egg->min_disk && $resources['disk'] < $egg->min_disk) {
            $warnings[] = "Plan provides {$resources['disk']}MB disk but {$egg->name} requires at least {$egg->min_disk}MB";
        }

        if ($egg->min_cpu && $resources['cpu'] < $egg->min_cpu) {
            $warnings[] = "Plan provides {$resources['cpu']}% CPU but {$egg->name} requires at least {$egg->min_cpu}%";
        }

        return $warnings;
    }
}
