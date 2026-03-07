import { Bars, LayoutCellsLarge } from '@gravity-ui/icons';
import { useCallback, useEffect, useState } from 'react';

import { ServerContext } from '@/state/server';
import { SocketEvent, SocketRequest } from '@/components/server/events';
import useWebsocketEvent from '@/plugins/useWebsocketEvent';
import { getPlayers, getBridgeStatus, type Player, type PlayerList, type BridgeStatus } from '@/api/server/players';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { Tabs, TabsList, TabsTrigger } from '@/components/elements/Tabs';
import PlayerCardGrid from '@/components/server/players/PlayerCardGrid';
import PlayerListView from '@/components/server/players/PlayerListView';
import RconStatusBadge from '@/components/server/players/RconStatusBadge';

/**
 * PlayersContainer is the main page for viewing and managing connected
 * game players. It fetches the initial player list via REST, then subscribes
 * to real-time updates via WebSocket RCON diffing.
 */
const PlayersContainer = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.id);
    const connected = ServerContext.useStoreState((state) => state.socket.connected);
    const instance = ServerContext.useStoreState((state) => state.socket.instance);

    const [players, setPlayers] = useState<Player[]>([]);
    const [count, setCount] = useState(0);
    const [max, setMax] = useState(0);
    const [loading, setLoading] = useState(true);
    const [status, setStatus] = useState<BridgeStatus | null>(null);
    const [view, setView] = useState<string>(() => localStorage.getItem('players_view') || 'grid');

    // Persist view preference.
    const handleViewChange = useCallback((v: string) => {
        setView(v);
        localStorage.setItem('players_view', v);
    }, []);

    // Initial data fetch.
    useEffect(() => {
        setLoading(true);
        Promise.all([getPlayers(uuid), getBridgeStatus(uuid)])
            .then(([playerData, statusData]) => {
                setPlayers(playerData.players);
                setCount(playerData.count);
                setMax(playerData.max);
                setStatus(statusData);
            })
            .catch(console.error)
            .finally(() => setLoading(false));
    }, [uuid]);

    // Subscribe to player events when websocket is connected.
    useEffect(() => {
        if (!connected || !instance) return;

        instance.send(SocketRequest.PLAYERS_SUBSCRIBE);

        return () => {
            instance.send(SocketRequest.PLAYERS_UNSUBSCRIBE);
        };
    }, [connected, instance]);

    // Handle full player list updates.
    useWebsocketEvent(SocketEvent.PLAYER_LIST, (data: string) => {
        try {
            const list: PlayerList = JSON.parse(data);
            setPlayers(list.players);
            setCount(list.count);
            setMax(list.max);
        } catch {
            // Ignore malformed events.
        }
    });

    // Handle individual join events (optimistic update before next full list).
    useWebsocketEvent(SocketEvent.PLAYER_JOIN, (data: string) => {
        try {
            const player: Player = JSON.parse(data);
            setPlayers((prev) => {
                if (prev.some((p) => p.name === player.name)) return prev;
                return [...prev, player];
            });
            setCount((c) => c + 1);
        } catch {
            // Ignore malformed events.
        }
    });

    // Handle individual leave events.
    useWebsocketEvent(SocketEvent.PLAYER_LEAVE, (data: string) => {
        try {
            const player: Player = JSON.parse(data);
            setPlayers((prev) => prev.filter((p) => p.name !== player.name));
            setCount((c) => Math.max(0, c - 1));
        } catch {
            // Ignore malformed events.
        }
    });

    return (
        <PageContentBlock title='Players'>
            <div className='flex items-center justify-between mb-4'>
                <div className='flex items-center gap-3'>
                    <h2 className='text-lg font-medium text-zinc-100'>
                        Players ({count}/{max})
                    </h2>
                    {status && <RconStatusBadge status={status} />}
                </div>
                <Tabs value={view} onValueChange={handleViewChange}>
                    <TabsList>
                        <TabsTrigger aria-label='View players in a list layout.' value='list'>
                            <Bars />
                        </TabsTrigger>
                        <TabsTrigger aria-label='View players in a grid layout.' value='grid'>
                            <LayoutCellsLarge />
                        </TabsTrigger>
                    </TabsList>
                </Tabs>
            </div>

            {status?.mode === 'fallback' && (
                <div className='mb-4 rounded-lg border border-yellow-800 bg-yellow-900/20 px-4 py-3 text-sm text-yellow-300'>
                    RCON is disabled. Player list and real-time updates are unavailable. Actions are sent but cannot be
                    confirmed.
                </div>
            )}

            {loading ? (
                <div className='text-center text-zinc-400 py-8'>Loading players...</div>
            ) : players.length === 0 ? (
                <div className='text-center text-zinc-400 py-8'>No players online</div>
            ) : view === 'grid' ? (
                <PlayerCardGrid players={players} serverUuid={uuid} />
            ) : (
                <PlayerListView players={players} serverUuid={uuid} />
            )}
        </PageContentBlock>
    );
};

export default PlayersContainer;
