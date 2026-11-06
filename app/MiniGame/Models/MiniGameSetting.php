<?php

namespace App\MiniGame\Models;

use Illuminate\Database\Eloquent\Model;

class MiniGameSetting extends Model
{
    protected $table = 'minigame_settings';

    protected $guarded = [];

    protected $casts = ['wheel_enabled' => 'boolean'];

    public static function wheelEnabled(): bool
    {
        return (bool) self::whereKey(1)->value('wheel_enabled');
    }
}
