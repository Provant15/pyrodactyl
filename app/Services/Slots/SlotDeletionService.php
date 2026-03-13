<?php

namespace Pterodactyl\Services\Slots;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Services\Elytra\ElytraJobService;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Services\Activity\ActivityLogService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Deletes a server slot and cleans up associated archived servers.
 *
 * The slot must be idle with no active server. Any archived servers linked
 * to the slot are deleted, and their Rustic snapshots are pruned via async
 * archive_delete jobs. Snapshot cleanup failures are logged but do not block
 * slot deletion.
 */
class SlotDeletionService
{
    public function __construct(
        private ConnectionInterface $connection,
        private ElytraJobService $elytraJobService,
        private ActivityLogService $activityLog,
    ) {}

    /**
     * Delete a server slot and its archived servers.
     *
     * @param ServerSlot $slot The slot to delete
     *
     * @throws ConflictHttpException If the slot has an active server or is not idle
     */
    public function handle(ServerSlot $slot): void
    {
        if ($slot->active_server_id !== null) {
            throw new ConflictHttpException('Cannot delete slot with an active server. Archive or remove the server first.');
        }

        if ($slot->status !== ServerSlot::STATUS_IDLE) {
            throw new ConflictHttpException('Cannot delete slot that is not idle. Current status: ' . $slot->status);
        }

        $this->connection->transaction(function () use ($slot) {
            $archivedServers = Server::where('slot_id', $slot->id)
                ->where('status', Server::STATUS_ARCHIVED)
                ->get();

            foreach ($archivedServers as $server) {
                if ($server->archive_snapshot_id) {
                    try {
                        $this->elytraJobService->submitJob(
                            $server,
                            'archive_delete',
                            ['snapshot_id' => $server->archive_snapshot_id, 'operation' => 'archive_delete'],
                            auth()->user() ?? $slot->user,
                        );
                    } catch (\Exception $e) {
                        logger()->warning("Failed to submit snapshot cleanup during slot deletion", [
                            'slot_id' => $slot->id,
                            'server_id' => $server->id,
                            'snapshot_id' => $server->archive_snapshot_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $server->delete();
            }

            $this->activityLog
                ->event('admin:slot.deleted')
                ->subject($slot)
                ->property([
                    'label' => $slot->label,
                    'archived_servers_deleted' => $archivedServers->count(),
                ])
                ->log();

            $slot->delete();
        });
    }
}
