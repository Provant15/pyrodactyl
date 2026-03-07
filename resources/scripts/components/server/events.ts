export enum SocketEvent {
    DAEMON_MESSAGE = 'daemon message',
    DAEMON_ERROR = 'daemon error',
    INSTALL_OUTPUT = 'install output',
    INSTALL_STARTED = 'install started',
    INSTALL_COMPLETED = 'install completed',
    CONSOLE_OUTPUT = 'console output',
    STATUS = 'status',
    STATS = 'stats',
    TRANSFER_LOGS = 'transfer logs',
    TRANSFER_STATUS = 'transfer status',
    BACKUP_COMPLETED = 'backup completed',
    BACKUP_STATUS = 'backup.status',
    BACKUP_RESTORE_COMPLETED = 'backup restore completed',
    PLAYER_LIST = 'player list',
    PLAYER_JOIN = 'player join',
    PLAYER_LEAVE = 'player leave',
}

export enum SocketRequest {
    SEND_LOGS = 'send logs',
    SEND_STATS = 'send stats',
    SET_STATE = 'set state',
    PLAYERS_SUBSCRIBE = 'players subscribe',
    PLAYERS_UNSUBSCRIBE = 'players unsubscribe',
}
