<?php

namespace App\Support;

/** 玩家看到的固定訊息。不透露內部原因；管理員在後台看實際狀態。 */
class PlayerMessages
{
    public const SUSPENDED = '帳號已停權，有疑慮請聯繫管理人員';

    public const TOPUP_FAILED = '充值失敗，請聯繫客服';

    public const ROUND_HELD = '此局暫停處理，請聯繫管理人員';
}
