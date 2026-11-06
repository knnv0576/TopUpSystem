<?php

namespace App\MiniGame\Exceptions;

use RuntimeException;

/** 轉盤請求不被接受，而且沒有動到點數。訊息可以直接給玩家看。 */
class SpinRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
