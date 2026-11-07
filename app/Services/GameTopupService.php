<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Package;
use App\Models\Player;
use App\Payments\MockCheckout;
use App\Payments\MockGateway;
use App\Support\PlayerMessages;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * 遊戲內充值：不接真實金流，但走完整的訂單、模擬付款資料驗證、模擬金流 Webhook 與入點流程。
 * 先建立訂單，玩家在轉盤頁的彈窗輸入付款資料後才送出付款；結果由測試卡號決定，請求不能指定情境。
 */
class GameTopupService
{
    public function __construct(private OrderService $orders, private MockGateway $gateway) {}

    public function create(int $gamePlayerId, string $requestId, int $packageId, string $method): Order
    {
        $player = Player::forGame($gamePlayerId);
        if ($player->isSuspended()) {
            throw new RuntimeException(PlayerMessages::SUSPENDED);
        }

        if ($existing = $this->find($player, $requestId)) {
            return $existing;
        }

        $package = Package::find($packageId);
        if (! $package) {
            throw new RuntimeException('找不到這個方案');
        }
        if ($reason = MockCheckout::methodUnavailableReason($method, $package->price)) {
            throw new RuntimeException($reason);
        }

        try {
            return $this->orders->create($player, $package, ['source' => 'game', 'request_id' => $requestId, 'payment_method' => $method]);
        } catch (UniqueConstraintViolationException) {
            return $this->find($player, $requestId);
        }
    }

    /**
     * 驗證付款資料後送出模擬付款。付款資料只在這裡驗證，不寫入資料庫或日誌。
     *
     * @return array<string, string> 付款資料的錯誤；空陣列表示已送出付款
     */
    public function pay(Order $order, array $input): array
    {
        if ($order->player->isSuspended()) {
            throw new RuntimeException(PlayerMessages::SUSPENDED);
        }
        if (! $order->isPayable()) {
            throw new RuntimeException($order->payment_submitted_at ? '這筆訂單已經送出付款，請等待結果' : '這筆訂單已不能付款，請重新充值');
        }

        [$errors, $scenario] = MockCheckout::validatePayment($order, $order->payment_method, $input, now());
        if ($errors !== []) {
            return $errors;
        }

        // 同一張訂單只送出一次付款：同時送出時只有一方會成功標記。
        if (! $order->claimPayment()) {
            throw new RuntimeException($order->fresh()->payment_submitted_at ? '這筆訂單已經送出付款，請等待結果' : '這筆訂單已不能付款，請重新充值');
        }

        $this->gateway->simulatePayment($order, $this->takeOverride() ?? $scenario);

        return [];
    }

    /** 測試面板設定的結果只用一次，會蓋過付款資料決定的結果；沒設定時由付款資料決定。 */
    private function takeOverride(): ?string
    {
        return Cache::pull(MockGateway::NEXT_GAME_TOPUP_CACHE_KEY);
    }

    private function find(Player $player, string $requestId): ?Order
    {
        return Order::where('player_id', $player->id)->where('request_id', $requestId)->first();
    }
}
