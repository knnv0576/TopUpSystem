<?php

namespace App\Http\Controllers\Admin;

use App\Payments\MockGateway;
use App\Services\DemoTools;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** DEMO 測試面板。這裡沒有任何直接修改點數的功能。 */
class DemoController
{
    public function __construct(private DemoTools $tools) {}

    public function index(): View
    {
        return view('admin.demo', [
            'payoutResults' => MockGateway::PAYOUT_RESULTS,
            'nextPayout' => Cache::get(MockGateway::NEXT_PAYOUT_CACHE_KEY, 'success'),
            'gameTopupResults' => MockGateway::GAME_TOPUP_RESULTS,
            'nextGameTopup' => Cache::get(MockGateway::NEXT_GAME_TOPUP_CACHE_KEY),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => 'required|in:RESET'], ['confirm.*' => '請輸入 RESET 以確認']);
        $this->tools->reset($this->admin());

        return back()->with('status', '測試資料已重置');
    }

    public function expirePacket(Request $request): RedirectResponse
    {
        $id = (int) $request->validate(['packet_id' => 'required|integer'])['packet_id'];

        return $this->attempt(fn () => $this->tools->expirePacket($id, $this->admin()), '紅包已到期並退回');
    }

    public function overdueRefund(Request $request): RedirectResponse
    {
        $id = (int) $request->validate(['refund_id' => 'required|integer'])['refund_id'];

        return $this->attempt(fn () => $this->tools->makeRefundOverdue($id, $this->admin()), '申請已設為逾時');
    }

    public function nextPayout(Request $request): RedirectResponse
    {
        $result = $request->validate(['result' => ['required', Rule::in(array_keys(MockGateway::PAYOUT_RESULTS))]])['result'];
        $this->tools->setNextPayout($result, $this->admin());

        return back()->with('status', '已設定下一次撥款結果');
    }

    public function nextGameTopup(Request $request): RedirectResponse
    {
        $result = $request->validate(['result' => ['required', Rule::in(array_keys(MockGateway::GAME_TOPUP_RESULTS))]])['result'];
        $this->tools->setNextGameTopup($result, $this->admin());

        return back()->with('status', '已設定下一次遊戲內充值結果');
    }

    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }

    private function admin()
    {
        return Auth::guard('admin')->user();
    }
}
