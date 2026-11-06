<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Package;
use App\Models\Player;
use Illuminate\Support\Str;
use RuntimeException;

class OrderService
{
    /** 價格與點數只取自伺服器的方案，並快照在訂單上。 */
    public function create(Player $player, Package $package, array $attributes = []): Order
    {
        if (! $package->active) {
            throw new RuntimeException('這個方案已下架');
        }

        $order = Order::create($attributes + [
            'order_no' => 'T'.now()->format('ymdHis').Str::upper(Str::random(10)),
            'player_id' => $player->id,
            'package_id' => $package->id,
            'package_name' => $package->name,
            'amount' => $package->price,
            'points' => $package->points(),
            'currency' => 'TWD',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(config('topup.order_ttl_minutes')),
        ]);

        AuditLog::record('order.created', $order, ['to' => 'pending', 'amount' => $order->amount, 'points' => $order->points, 'actor_type' => 'player', 'actor' => (string) $player->game_player_id]);

        return $order;
    }

    public function expireDue(): int
    {
        return Order::where('status', 'pending')->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }
}
