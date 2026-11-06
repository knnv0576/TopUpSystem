<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureShopPlayer;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Package;
use App\Models\Player;
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

        try {
            $order = $orders->create(Auth::guard('shop')->user(), $package);
        } catch (RuntimeException $e) {
            return back()->withErrors(['package_id' => $e->getMessage()]);
        }

        // 付款方式只是付款頁預設打開的分頁，不影響訂單內容。
        $checkout = $gateway->createCheckout($order);

        return redirect()->away(isset($data['method']) ? $checkout.'?method='.$data['method'] : $checkout);
    }

    /** 只顯示狀態，不作為付款依據。 */
    /** 剛付款的訂單完成後直接回玩家中心；之後再開訂單頁則照常顯示。 */
    public const RETURN_AFTER_ORDER = 'shop.return_after_order';

    public function showOrder(Request $request, string $orderNo): View|RedirectResponse
    {
        $order = $this->ownOrder($orderNo);

        if ($order->status === 'fulfilled' && $request->session()->get(self::RETURN_AFTER_ORDER) === $order->order_no) {
            $request->session()->forget(self::RETURN_AFTER_ORDER);

            return redirect()->route('game.dashboard')
                ->with('status', '充值完成，已入帳 '.number_format($order->points)." 點（訂單 {$order->order_no}）");
        }

        return view('shop.order', ['order' => $order]);
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
