<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Package;
use App\Models\Player;
use App\Payments\MockGateway;
use App\Support\PlayerMessages;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * 遊戲內充值：不接真實金流，但走完整的訂單、模擬金流 Webhook 與入點流程。
 * 付款結果只由伺服器決定，玩家的請求不能指定情境。
 */
class GameTopupService
{
    public function __construct(private OrderService $orders, private MockGateway $gateway) {}

    public function create(int $gamePlayerId, string $requestId, int $packageId): Order
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

        try {
            $order = $this->orders->create($player, $package, ['source' => 'game', 'request_id' => $requestId]);
        } catch (UniqueConstraintViolationException) {
            // 同一個請求併發時，只有先建立訂單的一方會送出付款。
            return $this->find($player, $requestId);
        }

        $this->gateway->simulatePayment($order, $this->takeNextScenario());

        return $order;
    }

    /** 測試面板設定的結果只用一次，之後回到付款成功。 */
    private function takeNextScenario(): string
    {
        return Cache::pull(MockGateway::NEXT_GAME_TOPUP_CACHE_KEY, 'success');
    }

    private function find(Player $player, string $requestId): ?Order
    {
        return Order::where('player_id', $player->id)->where('request_id', $requestId)->first();
    }
}
