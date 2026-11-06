<?php

namespace App\Services;

use App\Jobs\GrantPoints;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Player;
use App\Models\PointGrant;
use App\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;

class PaymentWebhookService
{
    public function __construct(private PaymentGateway $gateway, private RefundService $refunds) {}

    /** 事件已驗簽。同一個事件只會處理一次；重複通知直接視為成功。 */
    public function handle(array $event, array $payload): void
    {
        DB::transaction(function () use ($event, $payload) {
            $inserted = DB::table('gateway_events')->insertOrIgnore([
                'provider' => $this->gateway->name(),
                'event_id' => $event['event_id'],
                'type' => $event['type'],
                'payload' => json_encode($payload),
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                return;
            }

            match ($event['type']) {
                'payment.succeeded' => $this->paymentSucceeded($event),
                'payment.failed' => $this->paymentFailed($event),
                'payout.succeeded' => $this->refunds->payoutSucceeded($event),
                'payout.failed' => $this->refunds->payoutFailed($event),
                'payout.returned' => $this->refunds->payoutReturned($event),
                default => null,
            };
        });
    }

    private function paymentSucceeded(array $event): void
    {
        $order = Order::where('order_no', $event['order_no'])->lockForUpdate()->first();
        if (! $order) {
            return;
        }

        $matches = $event['amount'] === $order->amount && $event['currency'] === $order->currency;

        // 同一筆交易的不同事件也只記一次付款。
        Payment::firstOrCreate(
            ['provider' => $this->gateway->name(), 'provider_txn_id' => (string) $event['provider_txn_id']],
            ['order_id' => $order->id, 'amount' => $event['amount'], 'currency' => $event['currency'], 'status' => $matches ? 'succeeded' : 'amount_mismatch'],
        );

        if (! $matches) {
            Anomaly::raise('amount_mismatch', 'Order', $order->id, "訂單 {$order->order_no} 應付 {$order->amount} 元，通知金額 {$event['amount']} {$event['currency']}");
            AuditLog::record('payment.amount_mismatch', $order, ['amount' => $event['amount'], 'note' => '金額不符，不入點']);

            return;
        }

        if (in_array($order->status, ['paid', 'fulfilled'], true)) {
            return;
        }

        $from = $order->status;
        $order->update(['status' => 'paid']);

        // 鎖定玩家列，與停權互斥。
        $player = Player::whereKey($order->player_id)->lockForUpdate()->first();
        $held = $player->isSuspended();

        $grant = PointGrant::firstOrCreate(
            ['order_id' => $order->id],
            ['player_id' => $player->id, 'points' => $order->points, 'status' => $held ? 'held_suspended' : 'pending'],
        );

        AuditLog::record('payment.succeeded', $order, [
            'from' => $from,
            'to' => 'paid',
            'amount' => $order->amount,
            'points' => $order->points,
            'note' => $from === 'expired' ? '訂單過期後才收到付款，仍照常入點' : null,
        ]);

        if ($held) {
            Anomaly::raise('grant_held_suspended', 'PointGrant', $grant->id, "訂單 {$order->order_no} 付款時玩家已停權，解除後由覆核者手動補入 {$order->points} 點");
            AuditLog::record('grant.held_suspended', $grant, ['to' => 'held_suspended', 'points' => $order->points]);

            return;
        }

        GrantPoints::dispatch($grant->id)->afterCommit();
    }

    private function paymentFailed(array $event): void
    {
        $order = Order::where('order_no', $event['order_no'])->lockForUpdate()->first();
        if (! $order || $order->status !== 'pending') {
            return;
        }

        // 只接受已知的原因代碼，其他一律不記。
        $code = in_array($event['failure_code'] ?? null, Order::FAILURE_CODES, true) ? $event['failure_code'] : null;
        $order->update(['status' => 'failed', 'failure_code' => $code]);
        AuditLog::record('payment.failed', $order, ['from' => 'pending', 'to' => 'failed', 'amount' => $order->amount, 'note' => $code === 'card_declined' ? '發卡行拒絕交易' : null]);
    }
}
