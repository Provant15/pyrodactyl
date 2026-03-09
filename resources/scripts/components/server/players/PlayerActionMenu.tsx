import { useState } from 'react';
import { toast } from 'sonner';

import { type Player, type PlayerAction, executePlayerAction } from '@/api/server/players';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/elements/DropdownMenu';
import PlayerActionModal from '@/components/server/players/PlayerActionModal';

interface Props {
    player: Player;
    serverUuid: string;
}

/** All available player actions in display order. */
const allActions: PlayerAction[] = ['kick', 'ban', 'message', 'smite', 'teleport', 'gamemode', 'op', 'deop'];

/** Actions that require a modal for additional input. */
const modalActions: PlayerAction[] = ['kick', 'ban', 'message', 'teleport', 'gamemode'];

/**
 * PlayerActionMenu renders a dropdown button with all available player actions.
 * Uses Radix DropdownMenu with portal rendering to avoid overflow clipping
 * from ancestor containers.
 */
const PlayerActionMenu = ({ player, serverUuid }: Props) => {
    const [modalAction, setModalAction] = useState<PlayerAction | null>(null);

    const handleAction = async (action: PlayerAction, params?: Record<string, string>) => {
        try {
            const result = await executePlayerAction(serverUuid, player.name, action, params);
            if (result.success) {
                toast.success(`${action} executed on ${player.name}`);
            } else {
                toast.error(result.message || `Failed to ${action} ${player.name}`);
            }
        } catch (err: any) {
            toast.error(err?.message || `Failed to ${action} ${player.name}`);
        }
    };

    const handleClick = (action: PlayerAction) => {
        if (modalActions.includes(action)) {
            setModalAction(action);
        } else {
            handleAction(action);
        }
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button className='rounded p-1.5 text-zinc-400 hover:text-zinc-100 hover:bg-zinc-700'>
                        <svg className='h-4 w-4' fill='currentColor' viewBox='0 0 20 20'>
                            <path d='M10 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4z' />
                        </svg>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align='end'>
                    {allActions.map((action) => (
                        <DropdownMenuItem key={action} onSelect={() => handleClick(action)} className='capitalize'>
                            {action}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            {modalAction && (
                <PlayerActionModal
                    playerName={player.name}
                    action={modalAction}
                    open={!!modalAction}
                    onClose={() => setModalAction(null)}
                    onConfirm={(params) => handleAction(modalAction, params)}
                />
            )}
        </>
    );
};

export default PlayerActionMenu;
