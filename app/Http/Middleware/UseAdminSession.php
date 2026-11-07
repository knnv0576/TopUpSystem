<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 後台用獨立的 session cookie，只送往 /admin，閒置逾時也比玩家端短。
 * 玩家端的 cookie 被偷走時拿不到後台登入，反之亦然。
 * 必須在 StartSession 之前執行（加在 web 群組最前面）。
 */
class UseAdminSession
{
    public const COOKIE = 'demo-admin-session';

    /** 玩家端原本的設定，存在這個應用程式實例的 config 裡（每個測試各自一份）。 */
    private const DEFAULTS_KEY = 'session.player_defaults';

    public function handle(Request $request, Closure $next): Response
    {
        if (config(self::DEFAULTS_KEY) === null) {
            config([self::DEFAULTS_KEY => [
                'session.cookie' => config('session.cookie'),
                'session.path' => config('session.path'),
                'session.lifetime' => config('session.lifetime'),
                'session.expire_on_close' => config('session.expire_on_close'),
                'session.lottery' => config('session.lottery'),
            ]]);
        }

        // PHP-FPM 每個請求都是新的程序，這時 session store 還沒建立，改設定就會生效。
        // 若改用 Octane 等長駐程序，store 會跨請求沿用，要另外處理。
        config($request->is('admin', 'admin/*') ? [
            'session.cookie' => self::COOKIE,
            'session.path' => '/admin',
            'session.lifetime' => config('topup.admin_idle_minutes'),
            'session.expire_on_close' => true,
            // 玩家與後台共用 sessions 資料表；清除過期 session 時會用「這個請求」的逾時，
            // 後台的 15 分鐘會把閒置超過 15 分鐘的玩家 session 也刪掉，所以後台請求不做清除。
            // 後台 session 由玩家端請求以較長的逾時清除；過期判斷不受影響（讀取時就會檢查）。
            'session.lottery' => [0, 100],
        ] : config(self::DEFAULTS_KEY));

        return $next($request);
    }
}
