<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A resource slot owned by a user on a specific node. One active server, multiple archived.
 *
 * @property int $id
 * @property int $user_id
 * @property int $plan_id
 * @property int $node_id
 * @property int $allocation_id
 * @property int|null $active_server_id
 * @property string|null $label
 * @property int|null $memory_override
 * @property int|null $disk_override
 * @property int|null $cpu_override
 * @property int|null $io_override
 * @property int|null $swap_override
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Pterodactyl\Models\User $user
 * @property \Pterodactyl\Models\Plan $plan
 * @property \Pterodactyl\Models\Node $node
 * @property \Pterodactyl\Models\Allocation $allocation
 * @property \Pterodactyl\Models\Server|null $activeServer
 * @property \Illuminate\Database\Eloquent\Collection|\Pterodactyl\Models\Server[] $servers
 */
class ServerSlot extends Model
{
    public const RESOURCE_NAME = 'server_slot';

    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }

    public const STATUS_IDLE = 'idle';
    public const STATUS_DEPLOYING = 'deploying';
    public const STATUS_ARCHIVING = 'archiving';
    public const STATUS_RESTORING = 'restoring';

    protected $table = 'server_slots';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $attributes = [
        'status' => self::STATUS_IDLE,
        'active_server_id' => null,
    ];

    protected $casts = [
        'user_id' => 'integer',
        'plan_id' => 'integer',
        'node_id' => 'integer',
        'allocation_id' => 'integer',
        'active_server_id' => 'integer',
        'memory_override' => 'integer',
        'disk_override' => 'integer',
        'cpu_override' => 'integer',
        'io_override' => 'integer',
        'swap_override' => 'integer',
    ];

    public static array $validationRules = [
        'user_id' => 'required|exists:users,id',
        'plan_id' => 'required|exists:plans,id',
        'node_id' => 'required|exists:nodes,id',
        'allocation_id' => 'required|exists:allocations,id',
        'active_server_id' => 'nullable|integer',
        'label' => 'nullable|string|max:191',
        'memory_override' => 'nullable|integer|min:0',
        'disk_override' => 'nullable|integer|min:0',
        'cpu_override' => 'nullable|integer|min:0',
        'io_override' => 'nullable|integer|between:10,1000',
        'swap_override' => 'nullable|integer|min:-1',
        'status' => 'required|string|in:idle,deploying,archiving,restoring',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    /**
     * Returns the currently active (non-archived) server in this slot.
     * Uses application-level ID reference, not a FK.
     */
    public function activeServer(): HasOne
    {
        return $this->hasOne(Server::class, 'id', 'active_server_id');
    }

    /**
     * Returns all servers (active + archived) that belong to this slot.
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'slot_id');
    }

    /**
     * Returns the effective memory considering slot override.
     */
    public function effectiveMemory(): int
    {
        return $this->memory_override ?? $this->plan->memory;
    }

    /**
     * Returns the effective disk considering slot override.
     */
    public function effectiveDisk(): int
    {
        return $this->disk_override ?? $this->plan->disk;
    }

    /**
     * Returns the effective CPU considering slot override.
     */
    public function effectiveCpu(): int
    {
        return $this->cpu_override ?? $this->plan->cpu;
    }

    /**
     * Returns the effective IO considering slot override.
     */
    public function effectiveIo(): int
    {
        return $this->io_override ?? $this->plan->io;
    }

    /**
     * Returns the effective swap considering slot override.
     */
    public function effectiveSwap(): int
    {
        return $this->swap_override ?? $this->plan->swap;
    }

    /**
     * Checks whether this slot is idle (no operation in progress).
     */
    public function isIdle(): bool
    {
        return $this->status === self::STATUS_IDLE;
    }
}
