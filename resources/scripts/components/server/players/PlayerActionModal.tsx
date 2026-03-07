import { useEffect, useState } from 'react';
import { type PlayerAction } from '@/api/server/players';

interface Props {
    playerName: string;
    action: PlayerAction;
    open: boolean;
    onClose: () => void;
    onConfirm: (params: Record<string, string>) => void;
}

/** Maps action names to human-readable labels. */
const actionLabels: Record<PlayerAction, string> = {
    kick: 'Kick',
    ban: 'Ban',
    message: 'Message',
    smite: 'Smite',
    teleport: 'Teleport',
    gamemode: 'Change Gamemode',
    op: 'Grant Operator',
    deop: 'Revoke Operator',
};

/**
 * PlayerActionModal shows a confirmation dialog with context-specific
 * input fields for the selected player action.
 */
const PlayerActionModal = ({ playerName, action, open, onClose, onConfirm }: Props) => {
    const [reason, setReason] = useState('');
    const [message, setMessage] = useState('');
    const [coords, setCoords] = useState({ x: '', y: '', z: '' });
    const [target, setTarget] = useState('');
    const [gamemode, setGamemode] = useState('survival');

    // Reset form state when modal opens with a new action.
    useEffect(() => {
        if (open) {
            setReason('');
            setMessage('');
            setCoords({ x: '', y: '', z: '' });
            setTarget('');
            setGamemode('survival');
        }
    }, [open, action]);

    if (!open) return null;

    const handleConfirm = () => {
        const params: Record<string, string> = {};

        switch (action) {
            case 'kick':
            case 'ban':
                if (reason) params.reason = reason;
                break;
            case 'message':
                params.message = message;
                break;
            case 'teleport':
                if (target) {
                    params.target = target;
                } else {
                    params.x = coords.x;
                    params.y = coords.y;
                    params.z = coords.z;
                }
                break;
            case 'gamemode':
                params.mode = gamemode;
                break;
        }

        onConfirm(params);
        onClose();
    };

    return (
        <div className='fixed inset-0 z-50 flex items-center justify-center bg-black/50'>
            <div className='w-full max-w-md rounded-lg bg-zinc-800 border border-zinc-700 p-6'>
                <h3 className='text-lg font-medium text-zinc-100 mb-4'>
                    {actionLabels[action]} - {playerName}
                </h3>

                {(action === 'kick' || action === 'ban') && (
                    <input
                        type='text'
                        placeholder='Reason (optional)'
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        className='w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 mb-3'
                    />
                )}

                {action === 'message' && (
                    <input
                        type='text'
                        placeholder='Message'
                        value={message}
                        onChange={(e) => setMessage(e.target.value)}
                        className='w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 mb-3'
                        autoFocus
                    />
                )}

                {action === 'teleport' && (
                    <div className='space-y-2 mb-3'>
                        <input
                            type='text'
                            placeholder='Target player (or leave empty for coords)'
                            value={target}
                            onChange={(e) => setTarget(e.target.value)}
                            className='w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-sm text-zinc-100'
                        />
                        {!target && (
                            <div className='flex gap-2'>
                                <input
                                    type='text'
                                    placeholder='X'
                                    value={coords.x}
                                    onChange={(e) => setCoords({ ...coords, x: e.target.value })}
                                    className='w-1/3 rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-sm text-zinc-100'
                                />
                                <input
                                    type='text'
                                    placeholder='Y'
                                    value={coords.y}
                                    onChange={(e) => setCoords({ ...coords, y: e.target.value })}
                                    className='w-1/3 rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-sm text-zinc-100'
                                />
                                <input
                                    type='text'
                                    placeholder='Z'
                                    value={coords.z}
                                    onChange={(e) => setCoords({ ...coords, z: e.target.value })}
                                    className='w-1/3 rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-sm text-zinc-100'
                                />
                            </div>
                        )}
                    </div>
                )}

                {action === 'gamemode' && (
                    <select
                        value={gamemode}
                        onChange={(e) => setGamemode(e.target.value)}
                        className='w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 mb-3'
                    >
                        <option value='survival'>Survival</option>
                        <option value='creative'>Creative</option>
                        <option value='adventure'>Adventure</option>
                        <option value='spectator'>Spectator</option>
                    </select>
                )}

                <div className='flex justify-end gap-2 mt-4'>
                    <button
                        onClick={onClose}
                        className='rounded px-4 py-2 text-sm text-zinc-400 hover:text-zinc-100'
                    >
                        Cancel
                    </button>
                    <button
                        onClick={handleConfirm}
                        className='rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-500'
                    >
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    );
};

export default PlayerActionModal;
