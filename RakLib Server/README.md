# Процесс подключения к серверу


## Пинг
1. C->S   `0x01`   UNCONNECTED_PING_OPEN_CONNECTIONS
2. S->C   `0x1C`   UNCONNECTED_PONG


##	 Подключение
### RakNet Авторизация:

1. C->S `0x05` OPEN_CONNECTION_REQUEST_1
2. S->C `0x06` OPEN_CONNECTION_REPLY_1
3. C->S `0x07` OPEN_CONNECTION_REQUEST_2
4. S->C `0x08` OPEN_CONNECTION_REPLY_2
5. C->S `0x09` CLIENT_CONNECT
6. S->C `0x10` SERVER_HANDSHAKE
7. C->S `0x13` CLIENT_HANDSHAKE

Затем можно начинать пинговать клиента и готовиться к подключению к серверу

### Подключение к серверу:
1. C->S `0x8f` GAME_LOGIN
2. S->C `0x90` PLAYER_STATUS of 0
3. S->C `0x9d` MOVE_PLAYER (note: entity id of -1)
4. S->C `0x95` START_GAME (note: entity id of -1)
5. S->C `0xb1` SET_SPAWN_POSITION
6. S->C `0x9d` MOVE_PLAYER (note: entity id of -1)
7. S->C `0x94` SET_TIME
8. S->C `0xbc` ADVENTURE_SETTINGS
9. S->C `0xb3` RESPAWN
10.C->S `0xc8` REQUEST_CHUNK_RADIUS
11. S->C `0xc9` CHUNK_RADIUS_UPDATE (optional)
12. S->C `0xbf` FULL_CHUNK_DATA (batch packet)
13. S->C `0x90` PLAYER_STATUS of 3
14. S->C `0x94` SET_TIME