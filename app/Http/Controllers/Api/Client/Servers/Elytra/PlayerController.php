<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers\Elytra;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Server;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Players\GetPlayersRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Players\PlayerActionRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Players\PlayerCommandRequest;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use GuzzleHttp\Exception\TransferException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PlayerController proxies player management requests to the Elytra daemon.
 * All endpoints are gated by the server's egg having the `minecraft_rcon` feature.
 */
class PlayerController extends ClientApiController
{
    public function __construct(
        private DaemonServerRepository $repository,
    ) {
        parent::__construct();
    }

    /**
     * Verify the server's egg supports game bridge features.
     *
     * @throws NotFoundHttpException
     */
    private function ensureGameBridge(Server $server): void
    {
        $features = $server->egg->inherit_features ?? [];
        if (!in_array('minecraft_rcon', $features)) {
            throw new NotFoundHttpException('This server does not support player management.');
        }
    }

    /**
     * GET /players - Returns the current player list.
     */
    public function index(GetPlayersRequest $request, Server $server): JsonResponse
    {
        $this->ensureGameBridge($server);

        try {
            $response = $this->repository->setServer($server)->getHttpClient()->get(
                sprintf('/api/servers/%s/players', $server->uuid)
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }

        return new JsonResponse(
            json_decode($response->getBody()->__toString(), true),
            $response->getStatusCode()
        );
    }

    /**
     * POST /players/action - Executes an action on a player.
     * Player name is in the body (not URL) to avoid encoding issues.
     */
    public function action(PlayerActionRequest $request, Server $server): JsonResponse
    {
        $this->ensureGameBridge($server);

        try {
            $response = $this->repository->setServer($server)->getHttpClient()->post(
                sprintf('/api/servers/%s/players/action', $server->uuid),
                ['json' => $request->validated()]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }

        return new JsonResponse(
            json_decode($response->getBody()->__toString(), true),
            $response->getStatusCode()
        );
    }

    /**
     * POST /players/command - Sends a raw RCON command.
     */
    public function command(PlayerCommandRequest $request, Server $server): JsonResponse
    {
        $this->ensureGameBridge($server);

        try {
            $response = $this->repository->setServer($server)->getHttpClient()->post(
                sprintf('/api/servers/%s/players/command', $server->uuid),
                ['json' => $request->validated()]
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }

        return new JsonResponse(
            json_decode($response->getBody()->__toString(), true),
            $response->getStatusCode()
        );
    }

    /**
     * GET /players/status - Returns the game bridge connection status.
     */
    public function status(GetPlayersRequest $request, Server $server): JsonResponse
    {
        $this->ensureGameBridge($server);

        try {
            $response = $this->repository->setServer($server)->getHttpClient()->get(
                sprintf('/api/servers/%s/players/status', $server->uuid)
            );
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }

        return new JsonResponse(
            json_decode($response->getBody()->__toString(), true),
            $response->getStatusCode()
        );
    }
}
