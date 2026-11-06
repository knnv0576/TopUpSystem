<?php

namespace App\Game;

interface GameGateway
{
    public function grantPoints(int $gamePlayerId, int $points, string $key, string $memo): void;

    /** @throws InsufficientGamePoints */
    public function freezePoints(int $gamePlayerId, int $points, string $ref): void;

    public function unfreezePoints(int $gamePlayerId, int $points, string $ref): void;

    public function deductFrozenPoints(int $gamePlayerId, int $points, string $ref): void;

    public function returnDeductedPoints(int $gamePlayerId, int $points, string $ref): void;

    /** @throws InsufficientGamePoints */
    public function adjustPoints(int $gamePlayerId, string $direction, int $points, string $key): void;

    public function suspendPlayer(int $gamePlayerId): void;

    public function unsuspendPlayer(int $gamePlayerId): void;

    /** @return array{id:int,player_no:string,nickname:string,balance:int,frozen:int,suspended:bool}|null */
    public function findPlayer(string $playerNo): ?array;

    /** @return array{id:int,player_no:string,nickname:string,balance:int,frozen:int,suspended:bool}|null */
    public function getPlayer(int $gamePlayerId): ?array;
}
