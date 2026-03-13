<?php

namespace Pterodactyl\Http\Controllers\Api\Remote\Servers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Repositories\Eloquent\ServerRepository;
use Pterodactyl\Events\Server\Installed as ServerInstalled;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Pterodactyl\Http\Requests\Api\Remote\InstallationDataRequest;
use Pterodactyl\Models\ServerSlot;
use Pterodactyl\Services\Elytra\ElytraJobService;
use Illuminate\Support\Facades\Log;

class ServerInstallController extends Controller
{
    /**
     * ServerInstallController constructor.
     */
    public function __construct(private ServerRepository $repository, private EventDispatcher $eventDispatcher)
    {
    }

    /**
     * Returns installation information for a server.
     *
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $server = $this->repository->getByUuid($uuid);
        $egg = $server->egg;

        return new JsonResponse([
            'container_image' => $egg->copy_script_container,
            'entrypoint' => $egg->copy_script_entry,
            'script' => $egg->copy_script_install,
        ]);
    }

    /**
     * Updates the installation state of a server.
     *
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     */
    public function store(InstallationDataRequest $request, string $uuid): JsonResponse
    {
        $server = $this->repository->getByUuid($uuid);
        $status = null;

        // Make sure the type of failure is accurate
        if (!$request->boolean('successful')) {
            $status = Server::STATUS_INSTALL_FAILED;

            if ($request->boolean('reinstall')) {
                $status = Server::STATUS_REINSTALL_FAILED;
            }
        }

        // Keep the server suspended if it's already suspended
        if ($server->status === Server::STATUS_SUSPENDED) {
            $status = Server::STATUS_SUSPENDED;
        }

        $this->repository->update($server->id, ['status' => $status, 'installed_at' => CarbonImmutable::now()], true, true);

        // If the server successfully installed, fire installed event.
        // This logic allows individually disabling install and reinstall notifications separately.
        $isInitialInstall = is_null($server->installed_at);
        if ($isInitialInstall && config()->get('pterodactyl.email.send_install_notification', true)) {
            $this->eventDispatcher->dispatch(new ServerInstalled($server));
        } elseif (!$isInitialInstall && config()->get('pterodactyl.email.send_reinstall_notification', true)) {
            $this->eventDispatcher->dispatch(new ServerInstalled($server));
        }

        // Trigger restore Phase 2 overlay if this reinstall is part of a two-phase restore
        if ($request->boolean('successful')) {
            $this->checkForRestorePhaseTwo($server);
        }

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Check if a completed install/reinstall is part of a two-phase restore flow.
     *
     * If the server's slot is in STATUS_RESTORING and the server has an
     * archive_snapshot_id, this triggers Phase 2 (overlay) by submitting an
     * archive_restore job to Elytra via the ElytraJobService.
     *
     * @param Server $server The server that just completed installation
     */
    private function checkForRestorePhaseTwo(Server $server): void
    {
        if (empty($server->archive_snapshot_id)) {
            return;
        }

        $slot = ServerSlot::where('active_server_id', $server->id)
            ->where('status', ServerSlot::STATUS_RESTORING)
            ->first();

        if (!$slot) {
            return;
        }

        try {
            $elytraJobService = app(ElytraJobService::class);
            $elytraJobService->submitJob(
                $server,
                'archive_restore',
                [
                    'snapshot_id' => $server->archive_snapshot_id,
                    'operation' => 'archive_restore',
                ],
                $slot->user,
            );

            Log::info('Restore Phase 2 (overlay) triggered', [
                'server_id' => $server->id,
                'slot_id' => $slot->id,
                'snapshot_id' => $server->archive_snapshot_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to trigger restore Phase 2 (overlay)', [
                'server_id' => $server->id,
                'snapshot_id' => $server->archive_snapshot_id,
                'error' => $e->getMessage(),
            ]);
            // Reset state on failure
            $slot->update(['status' => ServerSlot::STATUS_IDLE, 'active_server_id' => null]);
            $server->update(['status' => Server::STATUS_ARCHIVED, 'allocation_id' => null]);
        }
    }
}
