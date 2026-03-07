import { type Player } from '@/api/server/players';
import PlayerActionMenu from '@/components/server/players/PlayerActionMenu';

interface Props {
    player: Player;
    serverUuid: string;
}

/**
 * PlayerListRow renders a single player as a table row with skin, name, and actions.
 */
const PlayerListRow = ({ player, serverUuid }: Props) => {
    const avatarUrl = player.uuid
        ? `https://mc-heads.net/avatar/${player.uuid}/24`
        : `https://mc-heads.net/avatar/${player.name}/24`;

    return (
        <tr className='border-b border-zinc-700 last:border-0'>
            <td className='py-2 px-3'>
                <div className='flex items-center gap-2'>
                    <img src={avatarUrl} alt={player.name} className='h-6 w-6 rounded' loading='lazy' />
                    <span className='text-sm text-zinc-100'>{player.name}</span>
                </div>
            </td>
            <td className='py-2 px-3 text-right'>
                <PlayerActionMenu player={player} serverUuid={serverUuid} />
            </td>
        </tr>
    );
};

export default PlayerListRow;
