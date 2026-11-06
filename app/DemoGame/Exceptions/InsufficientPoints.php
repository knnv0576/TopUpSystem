<?php

namespace App\DemoGame\Exceptions;

use RuntimeException;

class InsufficientPoints extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('可用點數不足');
    }
}
