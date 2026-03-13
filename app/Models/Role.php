<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Represents an admin role with a set of permission strings.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 * @property bool $is_system
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Database\Eloquent\Collection|\Pterodactyl\Models\RolePermission[] $permissions
 * @property \Illuminate\Database\Eloquent\Collection|\Pterodactyl\Models\User[] $users
 */
class Role extends Model
{
    public const RESOURCE_NAME = 'role';

    protected $table = 'roles';

    /**
     * Use integer ID for route model binding (base Model defaults to 'uuid').
     */
    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'is_default' => 'boolean',
        'is_system' => 'boolean',
    ];

    public static array $validationRules = [
        'name' => 'required|string|max:191',
        'description' => 'nullable|string',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
    ];

    /**
     * Returns all permission entries for this role.
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * Returns all users assigned to this role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user');
    }
}
