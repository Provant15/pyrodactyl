<?php

namespace Pterodactyl\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats a ServerSlot for Admin API responses.
 *
 * Includes effective resources (plan defaults with overrides applied),
 * and nested relationships via dedicated resources.
 *
 * @mixin \Pterodactyl\Models\ServerSlot
 */
class SlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'node_id' => $this->node_id,
            'plan_id' => $this->plan_id,
            'allocation_id' => $this->allocation_id,
            'active_server_id' => $this->active_server_id,
            'label' => $this->label,
            'status' => $this->status,
            'memory_override' => $this->memory_override,
            'disk_override' => $this->disk_override,
            'cpu_override' => $this->cpu_override,
            'io_override' => $this->io_override,
            'swap_override' => $this->swap_override,
            'effective_resources' => $this->when(
                $this->relationLoaded('plan'),
                fn () => [
                    'memory' => $this->memory_override ?? $this->plan->memory,
                    'disk' => $this->disk_override ?? $this->plan->disk,
                    'cpu' => $this->cpu_override ?? $this->plan->cpu,
                    'io' => $this->io_override ?? $this->plan->io,
                    'swap' => $this->swap_override ?? $this->plan->swap,
                ]
            ),
            'plan' => $this->whenLoaded('plan', fn () => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'memory' => $this->plan->memory,
                'disk' => $this->plan->disk,
                'cpu' => $this->plan->cpu,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'email' => $this->user->email,
            ]),
            'node' => $this->whenLoaded('node', fn () => [
                'id' => $this->node->id,
                'name' => $this->node->name,
                'fqdn' => $this->node->fqdn,
            ]),
            'active_server' => $this->whenLoaded('activeServer', fn () => [
                'id' => $this->activeServer->id,
                'name' => $this->activeServer->name,
                'status' => $this->activeServer->status,
                'egg_id' => $this->activeServer->egg_id,
            ]),
            'archived_servers' => ArchivedServerResource::collection(
                $this->whenLoaded('archivedServers')
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
