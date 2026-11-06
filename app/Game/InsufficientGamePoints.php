<?php

namespace App\Game;

use RuntimeException;

class InsufficientGamePoints extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('玩家可用點數不足');
    }
}
