<?php

namespace Pterodactyl\Services\Servers;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\ServerSlot;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Archives the current server and queues deployment of a new egg.
 *
 * Delegates to ServerArchiveService for the archive operation. After successful
 * archive submission, stores the new deployment parameters in the slot's
 * pending_deploy_data with a correlation token (the ElytraJob UUID). When
 * archive completes, ArchiveJob.processStatusUpdate() detects the pending data,
 * verifies the correlation token matches, and triggers ServerDeployService.
 *
 * Writing pending_deploy_data AFTER archive submission ensures no stale deploy
 * intent is left behind if the archive submission fails.
 */
class ServerSwapService
{
    public function __construct(
        private ServerArchiveService $archiveService,
    ) {}

    /**
     * Initiate a server swap: archive current + queue deploy of new egg.
     *
     * @param ServerSlot $slot The slot with an active server to swap
     * @param Egg $egg The new egg to deploy after archiving
     * @param array{name: string, start_on_completion?: bool} $deployOptions
     * @return array The archive job response from ElytraJobService
     *
     * @throws ConflictHttpException If slot has no active server
     */
    public function handle(ServerSlot $slot, Egg $egg, array $deployOptions): array
    {
        if ($slot->active_server_id === null) {
            throw new ConflictHttpException('Slot has no active server to swap.');
        }

        // Step 1: Submit archive (validates idle, locks slot, submits job)
        $result = $this->archiveService->handle($slot);

        // Step 2: Only after successful submission, store deploy intent with correlation
        $slot->update([
            'pending_deploy_data' => [
                'egg_id' => $egg->id,
                'name' => $deployOptions['name'],
                'start_on_completion' => $deployOptions['start_on_completion'] ?? false,
                'correlation_token' => $result['uuid'],
            ],
        ]);

        return $result;
    }
}
