<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Exception;
use Illuminate\View\View;
use Pterodactyl\Models\DatabaseHost;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Illuminate\View\Factory as ViewFactory;
use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Services\Databases\Hosts\HostUpdateService;
use Pterodactyl\Http\Requests\Admin\DatabaseHostFormRequest;
use Pterodactyl\Services\Databases\Hosts\HostCreationService;
use Pterodactyl\Services\Databases\Hosts\HostDeletionService;
use Pterodactyl\Services\Databases\Provisioners\ProvisionerFactory;
use Pterodactyl\Contracts\Repository\DatabaseRepositoryInterface;
use Pterodactyl\Contracts\Repository\LocationRepositoryInterface;
use Pterodactyl\Contracts\Repository\DatabaseHostRepositoryInterface;

class DatabaseController extends Controller
{
    /**
     * DatabaseController constructor.
     */
    public function __construct(
        private AlertsMessageBag $alert,
        private DatabaseHostRepositoryInterface $repository,
        private DatabaseRepositoryInterface $databaseRepository,
        private HostCreationService $creationService,
        private HostDeletionService $deletionService,
        private HostUpdateService $updateService,
        private LocationRepositoryInterface $locationRepository,
        private ViewFactory $view,
    ) {}

    /**
     * Display database host index.
     */
    public function index(): View
    {
        return $this->view->make('admin.databases.index', [
            'locations' => $this->locationRepository->getAllWithNodes(),
            'hosts' => $this->repository->getWithViewDetails(),
        ]);
    }

    /**
     * Display database host to user.
     *
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     */
    public function view(int $host): View
    {
        return $this->view->make('admin.databases.view', [
            'locations' => $this->locationRepository->getAllWithNodes(),
            'host' => $this->repository->find($host),
            'databases' => $this->databaseRepository->getDatabasesForHost($host),
        ]);
    }

    /**
     * Handle request to create a new database host.
     *
     * @throws \Throwable
     */
    public function create(DatabaseHostFormRequest $request): RedirectResponse
    {
        try {
            $host = $this->creationService->handle($request->normalize());
        } catch (\Exception $exception) {
            if ($exception instanceof \PDOException || $exception->getPrevious() instanceof \PDOException || $exception instanceof \RuntimeException) {
                $this->alert->danger(
                    sprintf('There was an error while trying to connect to the host or while executing a query: "%s"', $exception->getMessage())
                )->flash();

                return redirect()->route('admin.databases')->withInput($request->validated());
            } else {
                throw $exception;
            }
        }

        $this->alert->success('Successfully created a new database host on the system.')->flash();

        return redirect()->route('admin.databases.view', $host->id);
    }

    /**
     * Handle updating database host.
     *
     * @throws \Throwable
     */
    public function update(DatabaseHostFormRequest $request, DatabaseHost $host): RedirectResponse
    {
        $redirect = redirect()->route('admin.databases.view', $host->id);

        try {
            $this->updateService->handle($host->id, $request->normalize());
            $this->alert->success('Database host was updated successfully.')->flash();
        } catch (\Exception $exception) {
            // Catch any SQL related exceptions and display them back to the user, otherwise just
            // throw the exception like normal and move on with it.
            if ($exception instanceof \PDOException || $exception->getPrevious() instanceof \PDOException || $exception instanceof \RuntimeException) {
                $this->alert->danger(
                    sprintf('There was an error while trying to connect to the host or while executing a query: "%s"', $exception->getMessage())
                )->flash();

                return $redirect->withInput($request->normalize());
            } else {
                throw $exception;
            }
        }

        return $redirect;
    }

    /**
     * Handle request to delete a database host.
     *
     * @throws \Pterodactyl\Exceptions\Service\HasActiveServersException
     */
    public function delete(int $host): RedirectResponse
    {
        $this->deletionService->handle($host);
        $this->alert->success('The requested database host has been deleted from the system.')->flash();

        return redirect()->route('admin.databases');
    }

    /**
     * Test database connection credentials.
     *
     * Delegates to the appropriate driver-specific provisioner to verify
     * connectivity and check that the admin user has sufficient privileges
     * for database provisioning operations.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function testConnection(Request $request): JsonResponse
    {
        $this->validate($request, [
            'host' => 'required|string',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string',
            'password' => 'required|string',
            'driver' => 'required|string|in:mysql,pgsql',
        ]);

        try {
            // Build a temporary host model for the provisioner
            $host = new DatabaseHost();
            $host->forceFill([
                'host' => $request->input('host'),
                'port' => $request->input('port'),
                'username' => $request->input('username'),
                'password' => app(Encrypter::class)->encrypt($request->input('password')),
                'driver' => $request->input('driver'),
            ]);

            $factory = app(ProvisionerFactory::class);
            $provisioner = $factory->forHost($host);
            $result = $provisioner->testConnection($host);

            return response()->json([
                'success' => $result['has_required_permissions'],
                'message' => $result['message'],
                'version' => $result['version'],
                'has_required_permissions' => $result['has_required_permissions'],
            ], $result['has_required_permissions'] ? 200 : 422);
        } catch (\PDOException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 422);
        }
    }
}
