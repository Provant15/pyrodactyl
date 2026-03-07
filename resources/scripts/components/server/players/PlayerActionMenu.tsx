import { useState } from 'react';
import { toast } from 'sonner';

import { type Player, type PlayerAction, executePlayerAction } from '@/api/server/players';
import PlayerActionModal from '@/components/server/players/PlayerActionModal';

interface Props {
    player: Player;
    serverUuid: string;
}

/** Actions that require a modal for additional input. */
const modalActions: PlayerAction[] = ['kick', 'ban', 'message', 'teleport', 'gamemode'];

/**
 * PlayerActionMenu renders a dropdown button with all available player actions.
 */
const PlayerActionMenu = ({ player, serverUuid }: Props) => {
    const [open, setOpen] = useState(false);
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
        setOpen(false);
        if (modalActions.includes(action)) {
            setModalAction(action);
        } else {
            handleAction(action);
        }
    };

    return (
        <div className='relative'>
            <button
                onClick={() => setOpen(!open)}
                className='rounded p-1.5 text-zinc-400 hover:text-zinc-100 hover:bg-zinc-700'
            >
                <svg className='h-4 w-4' fill='currentColor' viewBox='0 0 20 20'>
                    <path d='M10 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4z' />
                </svg>
            </button>

            {open && (
                <>
                    <div className='fixed inset-0 z-40' onClick={() => setOpen(false)} />
                    <div className='absolute right-0 z-50 mt-1 w-40 rounded-lg border border-zinc-700 bg-zinc-800 py-1 shadow-xl'>
                        {(['kick', 'ban', 'message', 'smite', 'teleport', 'gamemode', 'op', 'deop'] as PlayerAction[]).map(
                            (action) => (
                                <button
                                    key={action}
                                    onClick={() => handleClick(action)}
                                    className='w-full px-3 py-1.5 text-left text-sm text-zinc-300 hover:bg-zinc-700 hover:text-zinc-100 capitalize'
                                >
                                    {action}
                                </button>
                            ),
                        )}
                    </div>
                </>
            )}

            {modalAction && (
                <PlayerActionModal
                    playerName={player.name}
                    action={modalAction}
                    open={!!modalAction}
                    onClose={() => setModalAction(null)}
                    onConfirm={(params) => handleAction(modalAction, params)}
                />
            )}
        </div>
    );
};

export default PlayerActionMenu;
