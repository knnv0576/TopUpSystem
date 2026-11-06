<?php

namespace App\Http\Middleware;

use App\Support\PlayerMessages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** 充值網站只接受單次 token 登入；停權或玩家改密碼會讓既有 session 立即失效。 */
class EnsureShopPlayer
{
    public const LOGIN_AT_KEY = 'shop_login_at';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('shop');
        $player = $guard->user();

        if (! $player) {
            return redirect()->route('game.login')->withErrors(['username' => '請先從玩家頁進入充值網站']);
        }

        if ($player->isSuspended()) {
            $guard->logout();
            $request->session()->regenerate();

            return redirect()->route('game.login')->withErrors(['username' => PlayerMessages::SUSPENDED]);
        }

        $session = $request->session();
        if (! $session->has(self::LOGIN_AT_KEY)) {
            $session->put(self::LOGIN_AT_KEY, now()->timestamp);
        } elseif ($player->sessions_revoked_at && $session->get(self::LOGIN_AT_KEY) <= $player->sessions_revoked_at->timestamp) {
            $guard->logout();
            $session->forget(self::LOGIN_AT_KEY);
            $session->regenerate();

            return redirect()->route('game.login')->withErrors(['username' => '帳號密碼已變更，請重新登入']);
        }

        Auth::shouldUse('shop');

        return $next($request);
    }
}
