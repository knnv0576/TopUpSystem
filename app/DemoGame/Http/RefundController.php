<?php

namespace App\DemoGame\Http;

use App\DemoGame\Services\TopupClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** 玩家端不保存撥款帳戶，只轉交給充值端；也沒有撤銷的路徑。 */
class RefundController
{
    public function index(TopupClient $topup): View
    {
        $player = Auth::guard('game')->user();

        return view('game.refunds', ['wallet' => $player->wallet, 'refunds' => $topup->refundRequests($player->id)]);
    }

    public function submit(Request $request, TopupClient $topup): RedirectResponse
    {
        $data = $request->validate([
            'request_id' => 'required|uuid',
            'points' => ['required', 'integer', 'min:'.config('topup.refund_min_points'), 'multiple_of:'.config('topup.refund_points_step')],
            'bank_code' => 'required|digits:3',
            'bank_account' => 'required|digits_between:6,16',
            'acknowledged' => 'required|accepted',
        ], [
            'points.min' => '單次至少 10,000 點',
            'points.multiple_of' => '點數須為 100 的倍數',
            'acknowledged.required' => '請先確認提示內容',
            'acknowledged.accepted' => '請先確認提示內容',
        ]);

        $player = Auth::guard('game')->user();

        if ($player->wallet->available() < (int) $data['points']) {
            return back()->withErrors(['points' => '可用點數不足'])->withInput($request->only('points', 'bank_code'));
        }

        $response = $topup->submitRefund([
            'game_request_id' => $data['request_id'],
            'game_player_id' => $player->id,
            'points' => (int) $data['points'],
            'bank_code' => $data['bank_code'],
            'bank_account' => $data['bank_account'],
        ]);

        if ($response->status() === 422) {
            return back()->withErrors(['points' => $response->json('error') ?? '申請內容不正確'])->withInput($request->only('points', 'bank_code'));
        }
        $response->throw();

        return redirect()->route('game.refunds')->with('status', '退款申請已送出，申請的點數已進入凍結流程');
    }
}
