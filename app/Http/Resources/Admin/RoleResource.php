<?php

namespace Pterodactyl\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms a Role model for the Admin API.
 *
 * @mixin \Pterodactyl\Models\Role
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_default' => $this->is_default,
            'is_system' => $this->is_system,
            'permissions' => $this->whenLoaded('permissions', fn () =>
                $this->permissions->pluck('permission')->all()
            ),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
