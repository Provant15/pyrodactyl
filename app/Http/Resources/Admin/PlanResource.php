<?php

namespace Pterodactyl\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms a Plan model for the Admin API.
 *
 * @mixin \Pterodactyl\Models\Plan
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'resources' => [
                'memory' => $this->memory,
                'disk' => $this->disk,
                'cpu' => $this->cpu,
                'io' => $this->io,
                'swap' => $this->swap,
                'oom_disabled' => $this->oom_disabled,
                'threads' => $this->threads,
            ],
            'limits' => [
                'databases' => $this->databases_limit,
                'backups' => $this->backups_limit,
                'allocations' => $this->allocations_limit,
                'archives' => $this->archive_limit,
            ],
            'is_default' => $this->is_default,
            'slots_count' => $this->whenCounted('slots'),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
