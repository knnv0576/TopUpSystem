<?php

use App\DemoGame\Models\GamePlayer;
use App\Models\AdminUser;
use App\Models\Player;

return [
    'defaults' => [
        'guard' => 'game',
        'passwords' => 'game_players',
    ],

    'guards' => [
        'game' => ['driver' => 'session', 'provider' => 'game_players'],
        'shop' => ['driver' => 'session', 'provider' => 'players'],
        'admin' => ['driver' => 'session', 'provider' => 'admin_users'],
    ],

    'providers' => [
        'game_players' => ['driver' => 'eloquent', 'model' => GamePlayer::class],
        'players' => ['driver' => 'eloquent', 'model' => Player::class],
        'admin_users' => ['driver' => 'eloquent', 'model' => AdminUser::class],
    ],

    'passwords' => [],

    'password_timeout' => 10800,
];
