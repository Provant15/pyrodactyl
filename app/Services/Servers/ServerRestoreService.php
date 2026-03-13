<?php

namespace Pterodactyl\Services\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Services\Activity\ActivityLogService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Orchestrates two-phase restore of an archived server into a slot.
 *
 * Phase 1 (Reinstall): Triggers the existing reinstall API on Elytra to lay
 * down fresh game binaries from the egg's install script.
 *
 * Phase 2 (Overlay): After reinstall completes (detected by ServerInstallController),
 * an archive_restore job is submitted via ElytraJobService to overlay the
 * archived user data from the Rustic snapshot onto the fresh install.
 *
 * This service initiates Phase 1 only. It rebinds the slot's allocation to the
 * server and sets the slot to STATUS_RESTORING.
 */
class ServerRestoreService
{
    public function __construct(
        private ConnectionInterface $connection,
        private DaemonServerRepository $daemonRepository,
        private ActivityLogService $activityLog,
    ) {}

    /**
     * Initiate a two-phase restore of an archived server.
     *
     * @param ServerSlot $slot The target slot (must be idle, no active server)
     * @param Server $server The archived server (must have archive_snapshot_id, same node as slot)
     *
     * @throws ConflictHttpException If slot is not available
     * @throws UnprocessableEntityHttpException If server is not properly archived or on wrong node
     */
    public function handle(ServerSlot $slot, Server $server): void
    {
        $this->validateArchivedServer($server, $slot);

        $this->connection->transaction(function () use ($slot, $server) {
            $slot = ServerSlot::where('id', $slot->id)->lockForUpdate()->firstOrFail();
            $this->validateSlotAvailability($slot);

            $server->update([
                'status' => Server::STATUS_INSTALLING,
                'allocation_id' => $slot->allocation_id,
                'slot_id' => $slot->id,
            ]);

            $slot->update([
                'status' => ServerSlot::STATUS_RESTORING,
                'active_server_id' => $server->id,
            ]);

            try {
                $this->daemonRepository->setServer($server)->reinstall();

                $this->activityLog
                    ->event('admin:slot.restore_initiated')
                    ->subject($slot)
                    ->property([
                        'server_id' => $server->id,
                        'snapshot_id' => $server->archive_snapshot_id,
                        'phase' => 'reinstall',
                    ])
                    ->log();
            } catch (\Throwable $e) {
                $server->update([
                    'status' => Server::STATUS_ARCHIVED,
                    'allocation_id' => null,
                    'slot_id' => null,
                ]);
                $slot->update([
                    'status' => ServerSlot::STATUS_IDLE,
                    'active_server_id' => null,
                ]);
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
            throw new ConflictHttpException('Slot already has an active server.');
        }
    }

    /**
     * @throws UnprocessableEntityHttpException
     */
    private function validateArchivedServer(Server $server, ServerSlot $slot): void
    {
        if ($server->status !== Server::STATUS_ARCHIVED) {
            throw new UnprocessableEntityHttpException('Server is not archived.');
        }

        if (empty($server->archive_snapshot_id)) {
            throw new UnprocessableEntityHttpException('Archived server has no snapshot ID.');
        }

        if ($server->node_id !== $slot->node_id) {
            throw new UnprocessableEntityHttpException(
                'Archived server is on a different node than the target slot. Cross-node restore is not supported.'
            );
        }
    }
}
