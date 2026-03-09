<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Players;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

/**
 * Authorizes requests to send raw RCON commands to a server.
 */
class PlayerCommandRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_PLAYERS_COMMAND;
    }

    public function rules(): array
    {
        return [
            'command' => 'required|string|max:500',
        ];
    }
}
