<?php

namespace Pterodactyl\Services\Elytra\Jobs;

use Pterodactyl\Contracts\Elytra\Job;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\ElytraJob;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Repositories\Elytra\ElytraRepository;
use Pterodactyl\Services\Servers\ServerDeployService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Handles archive-related async jobs via the ElytraJobService pattern.
 *
 * Supports three operations:
 * - archive_create: Snapshot server data via Rustic, mark server as archived
 * - archive_restore: Overlay archived data onto a freshly reinstalled server
 * - archive_delete: Delete a Rustic snapshot from the repository
 *
 * Completion handling includes swap continuation (checking correlation tokens
 * in pending_deploy_data) and archive limit enforcement.
 */
class ArchiveJob implements Job
{
    public function __construct(
        private ServerDeployService $deployService,
    ) {}

    /**
     * @return string[]
     */
    public static function getSupportedJobTypes(): array
    {
        return ['archive_create', 'archive_restore', 'archive_delete'];
    }

    /**
     * @return string[]
     */
    public function getRequiredPermissions(string $operation): array
    {
        return ['admin:slots.*'];
    }

    /**
     * Validate job data based on operation type.
     *
     * @param array $jobData Raw job data
     * @return array Validated data
     *
     * @throws \Exception If validation fails
     */
    public function validateJobData(array $jobData): array
    {
        $rules = match ($jobData['operation'] ?? $jobData['job_type'] ?? '') {
            'archive_create' => [
                'excludes' => 'nullable|array',
                'excludes.*' => 'string',
            ],
            'archive_restore' => [
                'snapshot_id' => 'required|string',
            ],
            'archive_delete' => [
                'snapshot_id' => 'required|string',
            ],
            default => throw new \Exception('Unknown archive operation'),
        };

        $validator = Validator::make($jobData, $rules);
        if ($validator->fails()) {
            throw new \Exception('Invalid job data: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validated() + ['operation' => $jobData['operation'] ?? $jobData['job_type'] ?? ''];
    }

    /**
     * Submit the archive job to Elytra via ElytraRepository.
     *
     * @return string The Elytra job ID
     */
    public function submitToElytra(Server $server, ElytraJob $job, ElytraRepository $elytraRepository): string
    {
        $operation = $job->job_data['operation'] ?? $job->job_type;

        $elytraJobData = match ($operation) {
            'archive_create' => $this->buildCreateJobData($server, $job),
            'archive_restore' => $this->buildRestoreJobData($server, $job),
            'archive_delete' => $this->buildDeleteJobData($server, $job),
            default => throw new \Exception("Unknown archive operation: {$operation}"),
        };

        $response = $elytraRepository->setServer($server)->createJob($operation, $elytraJobData);

        if (empty($response['job_id'])) {
            throw new \Exception('No job ID returned from Elytra');
        }

        return $response['job_id'];
    }

    /**
     * Cancel an archive job on Elytra.
     */
    public function cancelOnElytra(Server $server, ElytraJob $job, ElytraRepository $elytraRepository): void
    {
        if (!$job->elytra_job_id) {
            throw new \Exception('No Elytra job ID to cancel');
        }

        $elytraRepository->setServer($server)->cancelJob($job->elytra_job_id);
    }

    /**
     * Process status updates from Elytra for completed/failed archive jobs.
     *
     * Routes to operation-specific handlers that manage server state, slot state,
     * swap continuation, and archive limits.
     */
    public function processStatusUpdate(ElytraJob $job, array $statusData): void
    {
        $operation = $this->resolveOperation($statusData, $job);
        $successful = $statusData['successful'] ?? false;

        $job->update([
            'status' => $successful ? ElytraJob::STATUS_COMPLETED : ElytraJob::STATUS_FAILED,
            'progress' => $successful ? 100 : $job->progress,
            'status_message' => $statusData['message'] ?? null,
            'error_message' => $successful ? null : ($statusData['error_message'] ?? 'Archive operation failed.'),
            'completed_at' => now(),
        ]);

        if (!$successful) {
            $this->handleFailure($job, $operation, $statusData);
            return;
        }

        match ($operation) {
            'archive_create' => $this->handleArchiveCreateCompleted($job, $statusData),
            'archive_restore' => $this->handleArchiveRestoreCompleted($job, $statusData),
            'archive_delete' => null, // No panel-side state change needed
            default => Log::warning("Unknown archive operation in status update: {$operation}"),
        };
    }

    /**
     * Format job data for API responses.
     */
    public function formatJobResponse(ElytraJob $job): array
    {
        return [
            'uuid' => $job->uuid,
            'type' => $job->job_type,
            'status' => $job->status,
            'progress' => $job->progress,
            'operation' => $job->job_data['operation'] ?? $job->job_type,
        ];
    }

    // --- Private: Job data builders ---

    /**
     * Build the payload for an archive_create job submission.
     */
    private function buildCreateJobData(Server $server, ElytraJob $job): array
    {
        $excludes = $job->job_data['excludes'] ?? [];

        return [
            'server_id' => $server->uuid,
            'excludes' => implode("\n", $excludes),
        ];
    }

    /**
     * Build the payload for an archive_restore job submission.
     */
    private function buildRestoreJobData(Server $server, ElytraJob $job): array
    {
        return [
            'server_id' => $server->uuid,
            'snapshot_id' => $job->job_data['snapshot_id'],
        ];
    }

    /**
     * Build the payload for an archive_delete job submission.
     */
    private function buildDeleteJobData(Server $server, ElytraJob $job): array
    {
        return [
            'server_id' => $server->uuid,
            'snapshot_id' => $job->job_data['snapshot_id'],
        ];
    }

    // --- Private: Completion handlers ---

    /**
     * Handle archive_create completion: mark server archived, update slot,
     * enforce archive limit, check for swap continuation.
     */
    private function handleArchiveCreateCompleted(ElytraJob $job, array $statusData): void
    {
        $server = $job->server;
        $snapshotId = $statusData['snapshot_id'] ?? null;

        DB::transaction(function () use ($job, $server, $snapshotId) {
            // Mark server as archived
            $server->update([
                'status' => Server::STATUS_ARCHIVED,
                'archive_snapshot_id' => $snapshotId,
                'archived_at' => now(),
                'allocation_id' => null, // Release allocation for reuse
            ]);

            $slot = ServerSlot::where('active_server_id', $server->id)->first();
            if (!$slot) {
                return;
            }

            // Enforce archive limit before adding new archive
            $this->enforceArchiveLimit($slot);

            // Check for pending swap operation with correlation token
            $pendingDeploy = $slot->pending_deploy_data;
            $isSwap = $pendingDeploy
                && ($pendingDeploy['correlation_token'] ?? null) === $job->uuid;

            $slot->update([
                'active_server_id' => null,
                'status' => ServerSlot::STATUS_IDLE,
                'pending_deploy_data' => null,
            ]);

            // Execute swap continuation if correlation matches
            if ($isSwap) {
                $this->executeSwapDeploy($slot, $pendingDeploy);
            }
        });
    }

    /**
     * Handle archive_restore (overlay) completion: clear archive state, activate server.
     */
    private function handleArchiveRestoreCompleted(ElytraJob $job, array $statusData): void
    {
        $server = $job->server;

        DB::transaction(function () use ($server) {
            $server->update([
                'status' => null, // Active
                'archive_snapshot_id' => null,
                'archived_at' => null,
            ]);

            $slot = ServerSlot::where('active_server_id', $server->id)->first();
            if ($slot) {
                $slot->update(['status' => ServerSlot::STATUS_IDLE]);
            }
        });
    }

    /**
     * Handle job failure: reset slot to idle so it is not stuck.
     */
    private function handleFailure(ElytraJob $job, string $operation, array $statusData): void
    {
        Log::error("Archive job failed", [
            'job_uuid' => $job->uuid,
            'operation' => $operation,
            'server_id' => $job->server_id,
            'error' => $statusData['error_message'] ?? 'Unknown error',
        ]);

        $server = $job->server;
        $slot = ServerSlot::where('active_server_id', $server->id)->first();

        if ($operation === 'archive_create' && $slot) {
            $slot->update([
                'status' => ServerSlot::STATUS_IDLE,
                'pending_deploy_data' => null,
            ]);
        }

        if ($operation === 'archive_restore' && $slot) {
            $slot->update([
                'status' => ServerSlot::STATUS_IDLE,
            ]);
        }
    }

    /**
     * Execute the queued deploy after a successful swap archive.
     */
    private function executeSwapDeploy(ServerSlot $slot, array $pendingDeploy): void
    {
        try {
            $egg = Egg::findOrFail($pendingDeploy['egg_id']);
            $this->deployService->handle($slot, $egg, [
                'name' => $pendingDeploy['name'],
                'start_on_completion' => $pendingDeploy['start_on_completion'] ?? false,
            ]);
        } catch (\Throwable $e) {
            Log::error('Swap deploy failed after archive', [
                'slot_id' => $slot->id,
                'egg_id' => $pendingDeploy['egg_id'],
                'error' => $e->getMessage(),
            ]);
            // Slot is already idle from the archive completion - safe failure
        }
    }

    /**
     * Enforce the plan's archive_limit by deleting the oldest archived server.
     */
    private function enforceArchiveLimit(ServerSlot $slot): void
    {
        $slot->loadMissing('plan');
        $limit = $slot->plan->archive_limit ?? 0;
        if ($limit <= 0) {
            return;
        }

        $archivedCount = Server::where('slot_id', $slot->id)
            ->where('status', Server::STATUS_ARCHIVED)
            ->count();

        if ($archivedCount < $limit) {
            return;
        }

        $oldest = Server::where('slot_id', $slot->id)
            ->where('status', Server::STATUS_ARCHIVED)
            ->orderBy('archived_at', 'asc')
            ->first();

        if ($oldest) {
            if ($oldest->archive_snapshot_id) {
                try {
                    app(\Pterodactyl\Services\Elytra\ElytraJobService::class)->submitJob(
                        $oldest,
                        'archive_delete',
                        ['snapshot_id' => $oldest->archive_snapshot_id, 'operation' => 'archive_delete'],
                        $slot->user,
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to submit snapshot cleanup during archive limit enforcement', [
                        'server_id' => $oldest->id,
                        'snapshot_id' => $oldest->archive_snapshot_id,
                    ]);
                }
            }
            $oldest->delete();
        }
    }

    /**
     * Resolve the operation type from status data or job record.
     */
    private function resolveOperation(array $statusData, ElytraJob $job): string
    {
        return $statusData['job_type'] ?? $job->job_data['operation'] ?? $job->job_type;
    }
}
