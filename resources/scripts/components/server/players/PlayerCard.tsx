import { type Player } from '@/api/server/players';
import PlayerActionMenu from '@/components/server/players/PlayerActionMenu';

interface Props {
    player: Player;
    serverUuid: string;
}

/**
 * PlayerCard renders a single player in the grid view with their
 * Minecraft skin head, name, and action menu.
 */
const PlayerCard = ({ player, serverUuid }: Props) => {
    const avatarUrl = player.uuid
        ? `https://mc-heads.net/avatar/${player.uuid}/40`
        : `https://mc-heads.net/avatar/${player.name}/40`;

    return (
        <div className='flex items-center gap-3 rounded-lg border border-zinc-700 bg-zinc-800 p-3'>
            <img src={avatarUrl} alt={player.name} className='h-10 w-10 rounded' loading='lazy' />
            <div className='flex-1 min-w-0'>
                <p className='text-sm font-medium text-zinc-100 truncate'>{player.name}</p>
            </div>
            <PlayerActionMenu player={player} serverUuid={serverUuid} />
        </div>
    );
};

export default PlayerCard;
