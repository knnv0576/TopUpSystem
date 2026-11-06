<?php

namespace App\DemoGame\Http;

use App\DemoGame\Jobs\RevokeShopSessions;
use App\DemoGame\Models\GamePlayer;
use App\DemoGame\Services\TopupClient;
use App\Support\PlayerMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

class AuthController
{
    private const LOGIN_ERROR = '帳號或密碼錯誤，或帳號已暫時鎖定';

    public function showLogin(): View
    {
        return view('game.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => 'required|string|max:32', 'password' => 'required|string|max:200']);
        $player = GamePlayer::where('username', $data['username'])->first();

        if (! $player) {
            // 花費同樣的雜湊時間；輸入的帳號不寫入紀錄，有人可能把密碼打在帳號欄。
            Hash::make($data['password']);
            $this->event($request, 'player.login_failed', null, '帳號不存在');

            return $this->loginFailed();
        }

        if ($player->isLockedOut()) {
            $this->event($request, 'player.login_failed', $player, '帳號鎖定中');

            return $this->loginFailed();
        }

        if (! Hash::check($data['password'], $player->password)) {
            $this->event($request, 'player.login_failed', $player, '密碼錯誤');
            if ($player->registerFailure()) {
                $this->event($request, 'player.locked', $player, '連續登入失敗');
            }

            return $this->loginFailed();
        }

        if ($player->isSuspended()) {
            $this->event($request, 'player.login_failed', $player, '帳號已停權');

            return back()->withErrors(['username' => PlayerMessages::SUSPENDED])->onlyInput('username');
        }

        $player->clearFailures();
        Auth::guard('game')->login($player);
        $request->session()->regenerate();
        $request->session()->put($player->fingerprintSessionKey(), $player->sessionFingerprint());
        $this->event($request, 'player.login', $player);

        return redirect()->route('game.dashboard');
    }

    public function showRegister(): View
    {
        return view('game.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => 'required|string|min:3|max:32|alpha_dash:ascii|unique:game_players,username',
            'password' => ['required', 'confirmed', 'max:200', Password::min(10)->letters()->numbers()],
            'nickname' => 'required|string|min:1|max:32',
        ]);

        $player = GamePlayer::register($data['username'], $data['password'], $data['nickname']);
        Auth::guard('game')->login($player);
        $request->session()->regenerate();
        $request->session()->put($player->fingerprintSessionKey(), $player->sessionFingerprint());
        $this->event($request, 'player.registered', $player);

        return redirect()->route('game.dashboard');
    }

    public function showPassword(): View
    {
        return view('game.password');
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => 'required|string|max:200',
            'password' => ['required', 'confirmed', 'max:200', 'different:current_password', Password::min(10)->letters()->numbers()],
        ], ['password.different' => '新密碼不能與目前的密碼相同']);

        $player = Auth::guard('game')->user();

        if ($player->isLockedOut()) {
            $this->event($request, 'player.password_change_failed', $player, '帳號鎖定中');

            return back()->withErrors(['current_password' => '輸入錯誤次數過多，請稍後再試']);
        }

        if (! Hash::check($data['current_password'], $player->password)) {
            $this->event($request, 'player.password_change_failed', $player, '目前的密碼不正確');
            // 計入失敗次數：登入中的 session 被盜用時，不能拿這裡猜密碼。
            if ($player->registerFailure()) {
                $this->event($request, 'player.locked', $player, '連續輸入錯誤的目前密碼');
            }

            return back()->withErrors(['current_password' => '目前的密碼不正確']);
        }

        $player->update(['password' => $data['password']]);
        $player->clearFailures();

        // 這個 session 更新成新的指紋，繼續保持登入；其他裝置的玩家中心因此失效。
        $request->session()->regenerate();
        $request->session()->put($player->fingerprintSessionKey(), $player->fresh()->sessionFingerprint());
        $this->event($request, 'player.password_changed', $player);

        // 充值網站的登入是獨立的，要另外通知充值端一起失效。
        Auth::guard('shop')->logout();
        $this->revokeShopSessions($player->id);

        return redirect()->route('game.dashboard')->with('status', '密碼已更新');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->event($request, 'player.logout', Auth::guard('game')->user());

        Auth::guard('game')->logout();
        Auth::guard('shop')->logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('game.login');
    }

    /** 優先立即通知；失敗時交給可重試的工作，不影響已完成的改密碼。 */
    private function revokeShopSessions(int $playerId): void
    {
        try {
            app(TopupClient::class)->revokeSessions($playerId);
        } catch (Throwable $e) {
            report($e);
            RevokeShopSessions::dispatch($playerId);
        }
    }

    private function event(Request $request, string $event, ?GamePlayer $player, ?string $note = null): void
    {
        Log::channel('security')->info($event, [
            'player_no' => $player?->player_no,
            'ip' => $request->server('REMOTE_ADDR'),
            'note' => $note,
        ]);
    }

    private function loginFailed(): RedirectResponse
    {
        return back()->withErrors(['username' => self::LOGIN_ERROR])->onlyInput('username');
    }
}
