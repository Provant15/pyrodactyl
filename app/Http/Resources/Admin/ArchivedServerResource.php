<?php

namespace Pterodactyl\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats an archived server record for Admin API responses.
 *
 * Shows a subset of server data relevant to archive management.
 *
 * @mixin \Pterodactyl\Models\Server
 */
class ArchivedServerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'egg_id' => $this->egg_id,
            'archive_snapshot_id' => $this->archive_snapshot_id,
            'memory' => $this->memory,
            'disk' => $this->disk,
            'cpu' => $this->cpu,
            'egg' => $this->whenLoaded('egg', fn () => [
                'id' => $this->egg->id,
                'name' => $this->egg->name,
            ]),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
