<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A resource plan/template defining resource limits for server slots.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $memory
 * @property int $disk
 * @property int $cpu
 * @property int $io
 * @property int $swap
 * @property bool $oom_disabled
 * @property string|null $threads
 * @property int $databases_limit
 * @property int $backups_limit
 * @property int $allocations_limit
 * @property int $archive_limit
 * @property bool $is_default
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Database\Eloquent\Collection|\Pterodactyl\Models\ServerSlot[] $slots
 */
class Plan extends Model
{
    /** @use HasFactory<\Database\Factories\PlanFactory> */
    use HasFactory;

    public const RESOURCE_NAME = 'plan';

    protected $table = 'plans';

    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'memory' => 'integer',
        'disk' => 'integer',
        'cpu' => 'integer',
        'io' => 'integer',
        'swap' => 'integer',
        'oom_disabled' => 'boolean',
        'databases_limit' => 'integer',
        'backups_limit' => 'integer',
        'allocations_limit' => 'integer',
        'archive_limit' => 'integer',
        'is_default' => 'boolean',
    ];

    public static array $validationRules = [
        'name' => 'required|string|max:191',
        'description' => 'nullable|string',
        'memory' => 'required|integer|min:0',
        'disk' => 'required|integer|min:0',
        'cpu' => 'required|integer|min:0',
        'io' => 'required|integer|between:10,1000',
        'swap' => 'required|integer|min:-1',
        'oom_disabled' => 'boolean',
        'threads' => 'nullable|regex:/^[0-9-,]+$/',
        'databases_limit' => 'sometimes|integer|min:0',
        'backups_limit' => 'sometimes|integer|min:0',
        'allocations_limit' => 'sometimes|integer|min:0',
        'archive_limit' => 'sometimes|integer|min:0',
        'is_default' => 'boolean',
    ];

    /**
     * Returns all server slots using this plan.
     */
    public function slots(): HasMany
    {
        return $this->hasMany(ServerSlot::class, 'plan_id');
    }
}
