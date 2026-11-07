<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureShopPlayer;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Package;
use App\Models\Player;
use App\Payments\MockCheckout;
use App\Payments\PaymentGateway;
use App\Services\OrderService;
use App\Support\PlayerMessages;
use App\Support\SsoToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class ShopController
{
    /** 驗證單次 token：簽章、效期、未使用、玩家未停權，全部通過才建立 session。 */
    public function sso(Request $request): RedirectResponse
    {
        $claims = SsoToken::verify((string) $request->input('token'));
        if ($claims === null) {
            AuditLog::record('shop.sso_failed', null, ['actor_type' => 'anonymous', 'note' => '簽章錯誤或已過期']);

            return $this->ssoFailed();
        }

        $inserted = DB::table('sso_tokens')->insertOrIgnore([
            'jti' => $claims['jti'],
            'game_player_id' => $claims['sub'],
            'used_at' => now(),
            'expires_at' => now()->setTimestamp($claims['exp']),
        ]);
        if ($inserted === 0) {
            AuditLog::record('shop.sso_failed', null, ['actor_type' => 'player', 'actor' => (string) $claims['sub'], 'note' => 'token 重複使用']);

            return $this->ssoFailed();
        }

        $player = Player::forGame($claims['sub']);
        if ($player->isSuspended()) {
            AuditLog::record('shop.sso_failed', $player, ['actor_type' => 'player', 'actor' => (string) $claims['sub'], 'note' => '帳號已停權']);

            return redirect()->route('game.login')->withErrors(['username' => PlayerMessages::SUSPENDED]);
        }

        Auth::guard('shop')->login($player);
        $request->session()->regenerate();
        $request->session()->put(EnsureShopPlayer::LOGIN_AT_KEY, now()->timestamp);
        AuditLog::record('shop.login', $player, ['actor_type' => 'player', 'actor' => (string) $claims['sub']]);

        // 只接受固定的目的地，避免被當成任意轉址。
        return redirect()->route($request->input('next') === 'support' ? 'shop.support.index' : 'shop.index');
    }

    public function index(): View
    {
        return view('shop.index', ['packages' => Package::where('active', true)->orderBy('price')->get()]);
    }

    public function createOrder(Request $request, OrderService $orders, PaymentGateway $gateway): RedirectResponse
    {
        $data = $request->validate(['package_id' => 'required|integer', 'method' => 'nullable|in:card,atm,cvs'], ['package_id.required' => '請選擇儲值方案']);
        $package = Package::find($data['package_id']);
        if (! $package) {
            return back()->withErrors(['package_id' => '找不到這個方案']);
        }
        $method = $data['method'] ?? 'card';
        if ($reason = MockCheckout::methodUnavailableReason($method, $package->price)) {
            return back()->withInput()->withErrors(['method' => $reason]);
        }

        try {
            $order = $orders->create(Auth::guard('shop')->user(), $package, ['payment_method' => $method]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['package_id' => $e->getMessage()]);
        }

        // 付款方式記在訂單上，付款頁只顯示這一種；不影響訂單金額。
        return redirect()->away($gateway->createCheckout($order));
    }

    /** 只顯示狀態，不作為付款依據。 */
    /** 剛付款的訂單完成後直接回玩家中心；之後再開訂單頁則照常顯示。 */
    public const RETURN_AFTER_ORDER = 'shop.return_after_order';

    /** 剛送出付款、正在等金流通知的訂單與等待期限。 */
    public const AWAITING_NOTICE = 'shop.awaiting_notice';

    public function showOrder(Request $request, string $orderNo): View|RedirectResponse
    {
        $order = $this->ownOrder($orderNo);

        if ($order->status === 'fulfilled' && $request->session()->get(self::RETURN_AFTER_ORDER) === $order->order_no) {
            $request->session()->forget([self::RETURN_AFTER_ORDER, self::AWAITING_NOTICE]);

            return redirect()->route('game.dashboard')
                ->with('status', '充值完成，已入帳 '.number_format($order->points)." 點（訂單 {$order->order_no}）");
        }

        // 待付款的訂單只在剛送出付款後等待通知；已付款（入點中）一定會有結果，持續更新。
        // 玩家看到失敗（例如金額不符）的訂單要由客服處理，不再等待也不能再付款。
        // 遊戲內充值在轉盤頁付款，這裡不提供付款頁連結。
        $payable = $order->source === 'shop' && $order->isPayable();
        $awaiting = $request->session()->get(self::AWAITING_NOTICE);
        $waiting = $order->status === 'pending' && ! $order->failedForPlayer() && ($awaiting['order'] ?? null) === $order->order_no;
        $refresh = ($order->status === 'paid' && ! $order->failedForPlayer()) || ($waiting && now()->getTimestamp() < $awaiting['until']);

        return view('shop.order', ['order' => $order, 'payable' => $payable, 'refresh' => $refresh, 'noticeOverdue' => $waiting && ! $refresh]);
    }

    public function backToGame(): RedirectResponse
    {
        return redirect()->route('game.dashboard');
    }

    private function ownOrder(string $orderNo): Order
    {
        return Order::where('order_no', $orderNo)->where('player_id', Auth::guard('shop')->id())->firstOrFail();
    }

    private function ssoFailed(): RedirectResponse
    {
        return redirect()->route('game.login')->withErrors(['username' => '登入連結已失效，請重新從玩家頁進入']);
    }
}
