import { type Player } from '@/api/server/players';
import PlayerCard from '@/components/server/players/PlayerCard';

interface Props {
    players: Player[];
    serverUuid: string;
}

/**
 * PlayerCardGrid displays players in a responsive card grid layout.
 */
const PlayerCardGrid = ({ players, serverUuid }: Props) => (
    <div className='grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3'>
        {players.map((player) => (
            <PlayerCard key={player.name} player={player} serverUuid={serverUuid} />
        ))}
    </div>
);

export default PlayerCardGrid;
