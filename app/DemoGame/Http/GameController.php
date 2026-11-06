<?php

namespace App\DemoGame\Http;

use App\DemoGame\Services\TopupClient;
use App\DemoGame\Services\WalletService;
use App\Support\SsoToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class GameController
{
    public function dashboard(): View
    {
        $player = Auth::guard('game')->user();

        return view('game.dashboard', ['player' => $player, 'wallet' => $player->wallet]);
    }

    /** 完成任務：點數自訂，不設業務上限。 */
    public function task(Request $request, WalletService $wallets): RedirectResponse
    {
        $data = $request->validate(['points' => 'required|integer|min:1', 'request_id' => 'required|uuid']);
        $player = Auth::guard('game')->user();

        try {
            $wallets->credit($player->id, (int) $data['points'], 'task', "task:{$player->id}:{$data['request_id']}", '完成任務');
        } catch (RuntimeException $e) {
            return back()->withErrors(['points' => $e->getMessage()]);
        }

        return back()->with('status', '已取得 '.number_format((int) $data['points']).' 點');
    }

    public function nickname(Request $request): RedirectResponse
    {
        $data = $request->validate(['nickname' => 'required|string|min:1|max:32']);
        Auth::guard('game')->user()->update(['nickname' => $data['nickname']]);

        return back()->with('status', '暱稱已更新');
    }

    public function ledger(): View
    {
        $player = Auth::guard('game')->user();

        return view('game.ledger', [
            'player' => $player,
            'wallet' => $player->wallet,
            'entries' => DB::table('game_point_ledger')
                ->where('player_id', $player->id)
                ->where(fn ($q) => $q->where('delta_balance', '!=', 0)->orWhere('delta_frozen', '!=', 0))
                ->orderByDesc('id')
                ->paginate(30),
        ]);
    }

    public function orders(TopupClient $topup): View
    {
        return view('game.orders', ['orders' => $topup->orders(Auth::guard('game')->id())]);
    }

    /** 產生單次 token，用自動送出的表單 POST 給充值網站，token 不會留在網址。 */
    public function goShop(): View
    {
        return view('game.go-shop', ['token' => SsoToken::issue(Auth::guard('game')->id()), 'next' => null, 'title' => '前往充值網站']);
    }

    /** 客服頁在充值網站，同樣用單次 token 登入後轉過去。 */
    public function goSupport(): View
    {
        return view('game.go-shop', ['token' => SsoToken::issue(Auth::guard('game')->id()), 'next' => 'support', 'title' => '前往客服支援']);
    }
}
