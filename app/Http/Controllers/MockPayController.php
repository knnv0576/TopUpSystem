<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Payments\MockCheckout;
use App\Payments\MockGateway;
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
            throw ValidationException::withMessages(['method' => '這筆訂單已不是待付款狀態，無法付款']);
        }

        $scenario = match ($method) {
            'card' => $this->card($request),
            'atm' => $this->atm($request, $order),
            'cvs' => $this->cvs($request, $order),
            'advanced' => $this->advanced($request),
        };

        $gateway->simulatePayment($order, $scenario);
        $request->session()->put(ShopController::RETURN_AFTER_ORDER, $order->order_no);

        return redirect()->route('shop.orders.show', $order->order_no);
    }

    private function card(Request $request): string
    {
        [$errors, $scenario] = MockCheckout::validateCard($request->only(['card_name', 'card_number', 'card_expiry', 'card_cvv']), now());

        return $errors === [] ? $scenario : throw ValidationException::withMessages($errors);
    }

    private function atm(Request $request, Order $order): string
    {
        $errors = [];
        $bank = trim((string) $request->input('atm_bank'));
        $account = MockCheckout::digits((string) $request->input('atm_account'));
        $amount = trim((string) $request->input('atm_amount'));

        if (! preg_match('/^\d{3}$/', $bank)) {
            $errors['atm_bank'] = '轉入銀行代碼必須是 3 位數字';
        } elseif ($bank !== MockCheckout::ATM_BANK_CODE) {
            $errors['atm_bank'] = '轉入銀行代碼不正確';
        }
        if (! preg_match('/^\d{14}$/', $account)) {
            $errors['atm_account'] = '轉入帳號必須是 14 位數字';
        } elseif ($account !== MockCheckout::virtualAccount($order)) {
            $errors['atm_account'] = '轉入帳號與這筆訂單的虛擬帳號不符';
        }
        $errors += $this->amountError('atm_amount', $amount, $order, '轉帳金額');

        return $errors === [] ? 'success' : throw ValidationException::withMessages($errors);
    }

    private function cvs(Request $request, Order $order): string
    {
        if (! MockCheckout::cvsAvailable($order)) {
            throw ValidationException::withMessages(['method' => '超商代碼單筆上限 '.number_format(MockCheckout::CVS_LIMIT).' 元，請改用其他付款方式']);
        }

        $errors = [];
        $code = strtoupper(MockCheckout::digits((string) $request->input('cvs_code')));
        $amount = trim((string) $request->input('cvs_amount'));

        if (! preg_match('/^[A-Z0-9]{14}$/', $code)) {
            $errors['cvs_code'] = '繳費代碼必須是 14 個英文字母或數字';
        } elseif ($code !== MockCheckout::storeCode($order)) {
            $errors['cvs_code'] = '繳費代碼與這筆訂單不符';
        }
        $errors += $this->amountError('cvs_amount', $amount, $order, '繳費金額');

        return $errors === [] ? 'success' : throw ValidationException::withMessages($errors);
    }

    private function advanced(Request $request): string
    {
        $scenario = (string) $request->input('scenario');

        return array_key_exists($scenario, MockCheckout::ADVANCED_SCENARIOS)
            ? $scenario
            : throw ValidationException::withMessages(['scenario' => '請選擇異常情境']);
    }

    /** @return array<string, string> */
    private function amountError(string $field, string $amount, Order $order, string $label): array
    {
        if (! preg_match('/^\d{1,9}$/', $amount)) {
            return [$field => "{$label}必須是整數"];
        }
        if ((int) $amount !== $order->amount) {
            return [$field => "{$label}必須等於應繳金額 ".number_format($order->amount).' 元'];
        }

        return [];
    }

    private function payable(Order $order): bool
    {
        return $order->status === 'pending' && $order->expires_at->isFuture();
    }

    private function ownOrder(string $orderNo): Order
    {
        return Order::where('order_no', $orderNo)->where('player_id', Auth::guard('shop')->id())->firstOrFail();
    }
}
