<?php

namespace App\DemoGame\Http;

use App\DemoGame\Services\TopupClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/** 玩家端不保存撥款帳戶，只轉交給充值端；也沒有撤銷的路徑。 */
class RefundController
{
    public function index(TopupClient $topup): View
    {
        $player = Auth::guard('game')->user();

        return view('game.refunds', [
            'wallet' => $player->wallet,
            'refunds' => $topup->refundRequests($player->id),
            'accounts' => $topup->bankAccounts($player->id),
        ]);
    }

    public function submit(Request $request, TopupClient $topup): RedirectResponse
    {
        $data = $request->validate([
            'request_id' => 'required|uuid',
            'points' => ['required', 'integer', 'min:'.config('topup.refund_min_points'), 'multiple_of:'.config('topup.refund_points_step')],
            'bank_account_id' => 'required|integer|min:1',
            'acknowledged' => 'required|accepted',
        ], [
            'bank_account_id.required' => '請選擇撥款帳戶',
            'points.min' => '單次至少 10,000 點',
            'points.multiple_of' => '點數須為 100 的倍數',
            'acknowledged.required' => '請先確認提示內容',
            'acknowledged.accepted' => '請先確認提示內容',
        ]);

        $player = Auth::guard('game')->user();

        if ($player->wallet->available() < (int) $data['points']) {
            return back()->withErrors(['points' => '可用點數不足'])->withInput($request->only('points', 'bank_account_id'));
        }

        $response = $topup->submitRefund([
            'game_request_id' => $data['request_id'],
            'game_player_id' => $player->id,
            'points' => (int) $data['points'],
            'bank_account_id' => (int) $data['bank_account_id'],
        ]);

        if ($response->status() === 422) {
            return back()->withErrors(['points' => $response->json('error') ?? '申請內容不正確'])->withInput($request->only('points', 'bank_account_id'));
        }
        $response->throw();

        $message = $this->waitForFreeze((int) $response->json('id'), $player->id)
            ? '退款申請已送出，已凍結 '.number_format((int) $data['points']).' 點'
            : '退款申請已送出，點數凍結處理中，頁面會自動更新';

        return redirect()->route('game.refunds')->with('status', $message);
    }

    /** 綁定撥款帳戶：要再輸入一次密碼，避免帳號被盜用時撥款帳戶被偷偷換掉。 */
    public function addAccount(Request $request, TopupClient $topup): RedirectResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:30',
            'bank_code' => 'required|digits:3',
            'bank_account' => 'required|digits_between:6,16',
            'holder_name' => ['required', 'string', 'max:50', 'regex:/^[\p{Han}A-Za-z][\p{Han}A-Za-z ·.\-]{1,49}$/u'],
            'password' => 'required|string|max:200',
        ], [
            'holder_name.required' => '請輸入戶名',
            'holder_name.max' => '戶名最多 50 個字',
            'holder_name.regex' => '戶名請輸入中文或英文姓名（2 到 50 個字）',
            'label.required' => '請輸入帳戶名稱',
            'label.max' => '帳戶名稱最多 30 個字',
            'bank_code.required' => '請輸入銀行代碼',
            'bank_code.digits' => '銀行代碼必須是 3 位數字',
            'bank_account.required' => '請輸入帳號',
            'bank_account.digits_between' => '帳號必須是 6 到 16 位數字',
            'password.required' => '請輸入目前的密碼',
        ]);

        $player = Auth::guard('game')->user();
        if (! Hash::check($data['password'], $player->password)) {
            return back()->withErrors(['password' => '密碼不正確'])->withInput($request->only('label', 'bank_code'));
        }

        $response = $topup->addBankAccount($player->id, ['label' => $data['label'], 'bank_code' => $data['bank_code'], 'bank_account' => $data['bank_account'], 'holder_name' => $data['holder_name']]);
        if ($response->status() === 422) {
            return back()->withErrors(['bank_account' => $response->json('error') ?? '帳戶資料不正確'])->withInput($request->only('label', 'bank_code', 'holder_name'));
        }
        $response->throw();

        return redirect()->route('game.refunds')->with('status', '已綁定撥款帳戶 '.$response->json('masked').'（'.$response->json('label').'）');
    }

    /** 刪除綁定。審核中的申請已經複製了帳戶資料，仍會撥到原本選的帳戶。 */
    public function deleteAccount(int $account, TopupClient $topup): RedirectResponse
    {
        try {
            $topup->deleteBankAccount(Auth::guard('game')->id(), $account);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            if ($e->response->status() !== 404) {
                throw $e;
            }

            return redirect()->route('game.refunds')->withErrors(['bank_account' => '找不到這個撥款帳戶']);
        }

        return redirect()->route('game.refunds')->with('status', '已刪除撥款帳戶');
    }

    /**
     * 凍結由背景工作完成。短暫等待它寫入帳本，畫面上的可用點數才會立即正確；
     * 等不到也沒關係，退款頁會顯示「凍結中」並自動更新。
     */
    private function waitForFreeze(int $refundId, int $playerId): bool
    {
        $deadline = microtime(true) + config('topup.refund_freeze_wait_ms') / 1000;
        $frozen = fn () => DB::table('game_point_ledger')
            ->where('player_id', $playerId)
            ->where('idempotency_key', "refund:{$refundId}:freeze")
            ->exists();

        while (! $frozen()) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(200_000);
        }

        return true;
    }
}
