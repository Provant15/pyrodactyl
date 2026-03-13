<?php

namespace Pterodactyl\Services\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Services\Elytra\ElytraJobService;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Services\Activity\ActivityLogService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Initiates archival of a slot's active server via ElytraJobService.
 *
 * Locks the slot to STATUS_ARCHIVING and submits an archive_create job.
 * The async operation is handled by Elytra; completion is processed by
 * ArchiveJob.processStatusUpdate() when Elytra calls back.
 *
 * The egg's archive_excludes patterns are passed to Elytra so game binaries
 * (provided by the egg install script) are excluded from the snapshot.
 */
class ServerArchiveService
{
    public function __construct(
        private ConnectionInterface $connection,
        private ElytraJobService $elytraJobService,
        private ActivityLogService $activityLog,
    ) {}

    /**
     * Initiate archival of the slot's active server.
     *
     * @param ServerSlot $slot The slot with an active server to archive
     * @return array The ElytraJobService response (includes uuid, status)
     *
     * @throws ConflictHttpException If slot is not idle or has no active server
     */
    public function handle(ServerSlot $slot): array
    {
        return $this->connection->transaction(function () use ($slot) {
            $slot = ServerSlot::where('id', $slot->id)->lockForUpdate()->firstOrFail();

            if ($slot->status !== ServerSlot::STATUS_IDLE) {
                throw new ConflictHttpException('Slot is not idle. Current status: ' . $slot->status);
            }

            if ($slot->active_server_id === null) {
                throw new ConflictHttpException('Slot has no active server to archive.');
            }

            $server = Server::with('egg')->findOrFail($slot->active_server_id);
            $excludes = $server->egg->archive_excludes ?? [];

            $slot->update(['status' => ServerSlot::STATUS_ARCHIVING]);

            try {
                $result = $this->elytraJobService->submitJob(
                    $server,
                    'archive_create',
                    ['excludes' => $excludes],
                    auth()->user() ?? $slot->user,
                );

                $this->activityLog
                    ->event('admin:slot.archive_initiated')
                    ->subject($slot)
                    ->property([
                        'server_id' => $server->id,
                        'server_name' => $server->name,
                        'job_uuid' => $result['uuid'] ?? null,
                    ])
                    ->log();

                return $result;
            } catch (\Throwable $e) {
                $slot->update(['status' => ServerSlot::STATUS_IDLE]);
                throw $e;
            }
        });
    }
}
