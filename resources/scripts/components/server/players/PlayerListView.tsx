import { type Player } from '@/api/server/players';
import PlayerListRow from '@/components/server/players/PlayerListRow';

interface Props {
    players: Player[];
    serverUuid: string;
}

/**
 * PlayerListView displays players in a compact table layout.
 */
const PlayerListView = ({ players, serverUuid }: Props) => (
    <div className='rounded-lg border border-zinc-700 bg-zinc-800 overflow-hidden'>
        <table className='w-full'>
            <thead>
                <tr className='border-b border-zinc-700 text-left text-xs text-zinc-400 uppercase'>
                    <th className='py-2 px-3'>Player</th>
                    <th className='py-2 px-3 text-right'>Actions</th>
                </tr>
            </thead>
            <tbody>
                {players.map((player) => (
                    <PlayerListRow key={player.name} player={player} serverUuid={serverUuid} />
                ))}
            </tbody>
        </table>
    </div>
);

export default PlayerListView;
