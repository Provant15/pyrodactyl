<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single permission string entry linked to a role.
 *
 * @property int $id
 * @property int $role_id
 * @property string $permission
 * @property \Pterodactyl\Models\Role $role
 */
class RolePermission extends Model
{
    public $timestamps = false;

    protected $table = 'role_permissions';

    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }

    protected $guarded = ['id'];

    public static array $validationRules = [
        'role_id' => 'required|exists:roles,id',
        'permission' => 'required|string|max:191',
    ];

    /**
     * Returns the role that owns this permission.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
