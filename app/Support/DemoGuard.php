<?php

namespace App\Support;

use RuntimeException;

/** 這套系統只有模擬金流，不允許以 production 環境啟動。 */
class DemoGuard
{
    public static function assertNotProduction(string $environment): void
    {
        if ($environment === 'production') {
            throw new RuntimeException('這是只有模擬金流的本機 DEMO，不可在 production 環境啟動。');
        }
    }
}
