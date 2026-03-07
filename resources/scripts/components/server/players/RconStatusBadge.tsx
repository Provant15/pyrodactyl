import { type BridgeStatus } from '@/api/server/players';

interface Props {
    status: BridgeStatus;
}

/**
 * RconStatusBadge displays the current game bridge connection mode
 * as a small colored badge.
 */
const RconStatusBadge = ({ status }: Props) => {
    const isRcon = status.mode === 'rcon';
    const isConnected = status.connected;
    const label = !isConnected ? 'Disconnected' : isRcon ? 'RCON' : 'Fallback';

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                !isConnected
                    ? 'bg-red-900/30 text-red-400 border border-red-800'
                    : isRcon
                      ? 'bg-green-900/30 text-green-400 border border-green-800'
                      : 'bg-yellow-900/30 text-yellow-400 border border-yellow-800'
            }`}
        >
            <span
                className={`h-1.5 w-1.5 rounded-full ${
                    !isConnected ? 'bg-red-400' : isRcon ? 'bg-green-400' : 'bg-yellow-400'
                }`}
            />
            {label}
        </span>
    );
};

export default RconStatusBadge;
