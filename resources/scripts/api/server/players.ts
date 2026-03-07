import http from '@/api/http';
import { getGlobalDaemonType } from '@/api/server/getServer';

/**
 * Represents a connected game player.
 */
export interface Player {
    name: string;
    uuid?: string;
}

/**
 * Response from the player list endpoint.
 */
export interface PlayerList {
    players: Player[];
    count: number;
    max: number;
}

/**
 * Result of a player action or command execution.
 */
export interface ActionResult {
    success: boolean;
    message?: string;
    response?: string;
}

/**
 * Game bridge connection status.
 */
export interface BridgeStatus {
    connected: boolean;
    mode: 'rcon' | 'fallback';
    since?: string;
}

/**
 * Player action names supported by the game bridge.
 */
export type PlayerAction = 'kick' | 'ban' | 'message' | 'smite' | 'teleport' | 'gamemode' | 'op' | 'deop';

/**
 * Fetches the current player list for the given server.
 */
export const getPlayers = async (uuid: string): Promise<PlayerList> => {
    const { data } = await http.get(`/api/client/servers/${getGlobalDaemonType()}/${uuid}/players`);
    return data;
};

/**
 * Executes a named action on a player.
 * Player name is in the body (not URL) to avoid encoding issues.
 */
export const executePlayerAction = async (
    uuid: string,
    player: string,
    action: PlayerAction,
    params?: Record<string, string>,
): Promise<ActionResult> => {
    const { data } = await http.post(
        `/api/client/servers/${getGlobalDaemonType()}/${uuid}/players/action`,
        { player, action, params: params ?? {} },
    );
    return data;
};

/**
 * Sends a raw RCON command.
 */
export const executeCommand = async (uuid: string, command: string): Promise<ActionResult> => {
    const { data } = await http.post(`/api/client/servers/${getGlobalDaemonType()}/${uuid}/players/command`, {
        command,
    });
    return data;
};

/**
 * Fetches the game bridge connection status.
 */
export const getBridgeStatus = async (uuid: string): Promise<BridgeStatus> => {
    const { data } = await http.get(`/api/client/servers/${getGlobalDaemonType()}/${uuid}/players/status`);
    return data;
};
