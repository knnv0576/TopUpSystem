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

    /** 信件只能寄到本機的 Mailpit，避免 DEMO 資料寄到外部信箱。 */
    public static function assertLocalMail(string $mailer, ?string $host): void
    {
        if ($mailer === 'smtp' && $host !== 'mailpit') {
            throw new RuntimeException('這是本機 DEMO，SMTP 只能寄到 Docker 內的 mailpit，請檢查 MAIL_HOST。');
        }
        if (! in_array($mailer, ['smtp', 'array', 'log'], true)) {
            throw new RuntimeException('這是本機 DEMO，MAIL_MAILER 只能是 smtp（mailpit）、array 或 log。');
        }
    }
}
