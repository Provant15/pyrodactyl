<?php

namespace Pterodactyl\Services\Servers;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Services\Slots\SlotResourceService;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Services\Activity\ActivityLogService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Deploys an egg into a server slot.
 *
 * Resolves effective resources from the slot's plan and overrides, validates
 * them against the egg's minimum requirements, creates a server via the
 * existing ServerCreationService, and links it to the slot. Uses lockForUpdate()
 * for atomic slot status transitions.
 */
class ServerDeployService
{
    public function __construct(
        private ConnectionInterface $connection,
        private SlotResourceService $resourceService,
        private ServerCreationService $creationService,
        private ActivityLogService $activityLog,
    ) {}

    /**
     * Deploy an egg into a slot.
     *
     * @param ServerSlot $slot The target slot (must be idle with no active server)
     * @param Egg $egg The egg to deploy
     * @param array{name: string, start_on_completion?: bool, skip_validation?: bool} $options
     * @return Server The created server
     *
     * @throws ConflictHttpException If slot is not idle or has an active server
     * @throws UnprocessableEntityHttpException If resources are below egg minimums
     */
    public function handle(ServerSlot $slot, Egg $egg, array $options): Server
    {
        $slot->loadMissing('plan');
        $resources = $this->resourceService->resolve($slot);

        if (empty($options['skip_validation'])) {
            $warnings = $this->resourceService->validateForEgg($resources, $egg);
            if (!empty($warnings)) {
                throw new UnprocessableEntityHttpException(
                    'Resource validation failed: ' . implode('; ', $warnings)
                );
            }
        }

        return $this->connection->transaction(function () use ($slot, $egg, $options, $resources) {
            $slot = ServerSlot::where('id', $slot->id)->lockForUpdate()->firstOrFail();
            $this->validateSlotAvailability($slot);

            $slot->update(['status' => ServerSlot::STATUS_DEPLOYING]);

            try {
                $egg->loadMissing('nest');

                $server = $this->creationService->handle([
                    'name' => $options['name'],
                    'owner_id' => $slot->user_id,
                    'node_id' => $slot->node_id,
                    'egg_id' => $egg->id,
                    'nest_id' => $egg->nest_id,
                    'startup' => $egg->startup,
                    'image' => array_values($egg->docker_images)[0]
                        ?? throw new \RuntimeException('No Docker image configured for egg ' . $egg->name),
                    'allocation_id' => $slot->allocation_id,
                    'memory' => $resources['memory'],
                    'disk' => $resources['disk'],
                    'cpu' => $resources['cpu'],
                    'io' => $resources['io'],
                    'swap' => $resources['swap'],
                    'database_limit' => $resources['databases_limit'],
                    'backup_limit' => $resources['backups_limit'],
                    'allocation_limit' => $resources['allocations_limit'],
                    'start_on_completion' => $options['start_on_completion'] ?? false,
                ]);

                $server->update(['slot_id' => $slot->id]);
                $slot->update([
                    'active_server_id' => $server->id,
                    'status' => ServerSlot::STATUS_IDLE,
                ]);

                $this->activityLog
                    ->event('admin:slot.deployed')
                    ->subject($slot)
                    ->property([
                        'server_id' => $server->id,
                        'egg_id' => $egg->id,
                        'egg_name' => $egg->name,
                    ])
                    ->log();

                return $server;
            } catch (\Throwable $e) {
                $slot->update(['status' => ServerSlot::STATUS_IDLE]);
                throw $e;
            }
        });
    }

    /**
     * @throws ConflictHttpException
     */
    private function validateSlotAvailability(ServerSlot $slot): void
    {
        if ($slot->status !== ServerSlot::STATUS_IDLE) {
            throw new ConflictHttpException('Slot is not idle. Current status: ' . $slot->status);
        }

        if ($slot->active_server_id !== null) {
            throw new ConflictHttpException('Slot already has an active server. Archive or remove it first.');
        }
    }
}
