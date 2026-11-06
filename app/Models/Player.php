<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** 充值端的玩家紀錄。帳號由玩家端管理，這裡只存 game_player_id 與停權狀態。 */
class Player extends Authenticatable
{
    protected $guarded = [];

    protected $casts = ['suspended_at' => 'datetime', 'sessions_revoked_at' => 'datetime', 'game_player_id' => 'integer'];

    public static function forGame(int $gamePlayerId): self
    {
        return self::firstOrCreate(['game_player_id' => $gamePlayerId]);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function getRememberTokenName()
    {
        return '';
    }
}
