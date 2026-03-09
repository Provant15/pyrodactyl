<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Players;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

/**
 * Authorizes requests to execute actions on players (kick, ban, etc.).
 */
class PlayerActionRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_PLAYERS_ACTION;
    }

    public function rules(): array
    {
        return [
            'player' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]{1,16}$/'],
            'action' => 'required|string|in:kick,ban,message,smite,teleport,gamemode,op,deop',
            'params' => 'array',
            'params.*' => 'string',
        ];
    }
}
