<?php

namespace App\Http\Middleware;

use App\Support\PlayerMessages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** 每次請求都重新讀取玩家；停權或改密碼會讓既有 session 立即失效。 */
class EnsureGamePlayer
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('game');
        $player = $guard->user();

        if (! $player) {
            return redirect()->route('game.login');
        }

        if ($player->isSuspended()) {
            $guard->logout();
            $request->session()->regenerate();

            return redirect()->route('game.login')->withErrors(['username' => PlayerMessages::SUSPENDED]);
        }

        $session = $request->session();
        $key = $player->fingerprintSessionKey();
        if (! $session->has($key)) {
            $session->put($key, $player->sessionFingerprint());
        } elseif (! hash_equals($session->get($key), $player->sessionFingerprint())) {
            Log::channel('security')->info('player.session_invalidated', ['player_no' => $player->player_no, 'ip' => $request->server('REMOTE_ADDR')]);
            $guard->logout();
            Auth::guard('shop')->logout();
            $session->forget($key);
            $session->regenerate();

            return redirect()->route('game.login')->withErrors(['username' => '帳號密碼已變更，請重新登入']);
        }

        Auth::shouldUse('game');

        return $next($request);
    }
}
