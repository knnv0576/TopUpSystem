<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Payments\MockCheckout;
use App\Payments\MockGateway;
use App\Support\PlayerMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 模擬付款頁：驗證付款資料後由模擬金流送出 Webhook，這裡不會直接改訂單。
 * 信用卡卡號與安全碼只在這個請求裡驗證，不寫入資料庫、日誌或 session。
 */
class MockPayController
{
    private const METHODS = ['card', 'atm', 'cvs', 'advanced'];

    public function show(string $orderNo): View
    {
        $order = $this->ownOrder($orderNo);

        return view('shop.mock-pay', [
            'order' => $order,
            'payable' => $this->payable($order),
            'cards' => MockCheckout::TEST_CARDS,
            'brands' => MockCheckout::BRANDS,
            'advanced' => MockCheckout::ADVANCED_SCENARIOS,
            'atmBank' => MockCheckout::ATM_BANK_CODE,
            'atmAccount' => MockCheckout::virtualAccount($order),
            'cvsCode' => MockCheckout::storeCode($order),
            'cvsAvailable' => MockCheckout::cvsAvailable($order),
            'cvsLimit' => MockCheckout::CVS_LIMIT,
        ]);
    }

    public function pay(Request $request, string $orderNo, MockGateway $gateway): RedirectResponse
    {
        $order = $this->ownOrder($orderNo);
        $method = (string) $request->input('method');

        if (! in_array($method, self::METHODS, true)) {
            throw ValidationException::withMessages(['method' => '請選擇付款方式']);
        }
        if (! $this->payable($order)) {
            throw ValidationException::withMessages(['method' => $this->notPayableReason($order)]);
        }
        // 選方案時已決定付款方式；進階測試不是付款方式，不受限制。
        if ($order->payment_method !== null && $method !== 'advanced' && $method !== $order->payment_method) {
            throw ValidationException::withMessages(['method' => '這筆訂單的付款方式是'.Order::PAYMENT_METHODS[$order->payment_method].'，不能改用其他方式']);
        }

        if ($method === 'advanced') {
            $scenario = $this->advanced($request);
        } else {
            [$errors, $scenario] = MockCheckout::validatePayment($order, $method, $request->only(MockCheckout::PAYMENT_FIELDS), now());
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            // 一張訂單只送出一次付款；進階測試只送異常通知，不算付款。
            if (! $order->claimPayment()) {
                throw ValidationException::withMessages(['method' => $this->notPayableReason($order->fresh())]);
            }
        }

        $gateway->simulatePayment($order, $scenario);

        // 異常通知不會入點，不用到訂單頁等待結果。
        if ($method === 'advanced') {
            return redirect()->route('mock.pay.show', $order->order_no)->with('status', match ($scenario) {
                'bad_signature' => '已送出簽章錯誤的通知。系統會拒絕這筆通知，訂單仍是待付款，可以繼續付款或再測一次。',
                'amount_mismatch' => '已送出金額不符的通知。系統不會入點，這筆訂單會改為「充值失敗，請聯繫客服」並列入後台異常清單，不能再付款。',
            });
        }

        $request->session()->put(ShopController::RETURN_AFTER_ORDER, $order->order_no);
        // 訂單頁只在這段時間內自動更新，等待金流通知（含延遲通知）。
        $request->session()->put(ShopController::AWAITING_NOTICE, [
            'order' => $order->order_no,
            'until' => now()->addSeconds(config('topup.mock_delay_seconds') + 30)->getTimestamp(),
        ]);

        return redirect()->route('shop.orders.show', $order->order_no);
    }

    private function advanced(Request $request): string
    {
        $scenario = (string) $request->input('scenario');

        return array_key_exists($scenario, MockCheckout::ADVANCED_SCENARIOS)
            ? $scenario
            : throw ValidationException::withMessages(['scenario' => '請選擇異常情境']);
    }

    private function payable(Order $order): bool
    {
        return $order->isPayable();
    }

    private function notPayableReason(Order $order): string
    {
        return match (true) {
            $order->failedForPlayer() => PlayerMessages::TOPUP_FAILED.'，這筆訂單不能再付款',
            $order->status === 'pending' && $order->payment_submitted_at !== null => '這筆訂單已經送出付款，請等待結果',
            default => '這筆訂單已不是待付款狀態，無法付款',
        };
    }

    /** 只處理充值網站的訂單；遊戲內充值在轉盤頁的彈窗付款。 */
    private function ownOrder(string $orderNo): Order
    {
        return Order::where('order_no', $orderNo)->where('player_id', Auth::guard('shop')->id())->where('source', 'shop')->firstOrFail();
    }
}
