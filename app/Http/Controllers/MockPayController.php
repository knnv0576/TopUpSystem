<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Payments\MockGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 模擬付款頁：選擇情境後由模擬金流送出 Webhook，這裡不會直接改訂單。 */
class MockPayController
{
    public function show(string $orderNo): View
    {
        return view('shop.mock-pay', ['order' => $this->ownOrder($orderNo), 'scenarios' => MockGateway::PAYMENT_SCENARIOS]);
    }

    public function pay(Request $request, string $orderNo, MockGateway $gateway): RedirectResponse
    {
        $data = $request->validate(['scenario' => ['required', Rule::in(array_keys(MockGateway::PAYMENT_SCENARIOS))]]);
        $order = $this->ownOrder($orderNo);

        $gateway->simulatePayment($order, $data['scenario']);

        return redirect()->route('shop.orders.show', $order->order_no);
    }

    private function ownOrder(string $orderNo): Order
    {
        return Order::where('order_no', $orderNo)->where('player_id', Auth::guard('shop')->id())->firstOrFail();
    }
}
