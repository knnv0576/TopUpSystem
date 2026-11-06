<?php

namespace App\Payments;

use App\Jobs\DeliverMockWebhook;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 模擬金流。沒有任何真實款項；撥款結果可由後台測試面板設定。
 * Webhook 實際走 HTTP 並帶 HMAC 簽章，與真實金流的路徑相同。
 */
class MockGateway implements PaymentGateway
{
    public const NEXT_PAYOUT_CACHE_KEY = 'demo.next_payout_result';

    public const NEXT_GAME_TOPUP_CACHE_KEY = 'demo.next_game_topup_result';

    public const GAME_TOPUP_RESULTS = [
        'success' => '付款成功',
        'failed' => '付款失敗',
    ];

    public const PAYMENT_SCENARIOS = [
        'success' => '付款成功',
        'failed' => '付款失敗',
        'declined' => '付款失敗（發卡行拒絕交易）',
        'delayed' => '付款成功（延遲通知）',
        'duplicate' => '付款成功（重複通知）',
        'bad_signature' => '錯誤簽章',
        'amount_mismatch' => '金額不符',
    ];

    public const PAYOUT_RESULTS = [
        'success' => '撥款成功',
        'failed' => '撥款失敗',
        'delayed' => '撥款成功（延遲通知）',
        'error' => '呼叫逾時（暫時性錯誤）',
    ];

    public function name(): string
    {
        return 'mock';
    }

    /**
     * 模擬銀行的帳戶驗證。規則和測試卡號一樣用帳號結尾決定：
     * 9999 查無帳號、8888 戶名不符；7777 可以綁定，但撥款後會被退匯（見 payout）。
     */
    public function verifyAccount(string $bankCode, string $account, string $holderName): string
    {
        return match (true) {
            str_ends_with($account, '9999') => 'account_not_found',
            str_ends_with($account, '8888') => 'name_mismatch',
            default => 'matched',
        };
    }

    public function createCheckout(Order $order): string
    {
        return route('mock.pay.show', $order->order_no);
    }

    public function parseWebhook(Request $request): ?array
    {
        $body = $request->getContent();
        if (! hash_equals(self::sign($body), (string) $request->header('X-Mock-Signature'))) {
            return null;
        }

        $data = json_decode($body, true);
        if (! is_array($data) || ! isset($data['event_id'], $data['type'], $data['amount'], $data['currency'])) {
            return null;
        }

        return [
            'event_id' => (string) $data['event_id'],
            'type' => (string) $data['type'],
            'provider_txn_id' => $data['provider_txn_id'] ?? null,
            'order_no' => $data['order_no'] ?? null,
            'payout_key' => $data['payout_key'] ?? null,
            'amount' => (int) $data['amount'],
            'currency' => (string) $data['currency'],
            'failure_code' => isset($data['failure_code']) ? (string) $data['failure_code'] : null,
        ];
    }

    public function payout(string $idempotencyKey, int $amount, string $bankCode, string $account): string
    {
        $result = Cache::pull(self::NEXT_PAYOUT_CACHE_KEY, 'success');
        // 帳號結尾 7777：撥款先成功，之後銀行通知退匯。
        $returned = $result === 'success' && str_ends_with($account, '7777');

        if ($result === 'error') {
            throw new RuntimeException('模擬金流暫時無法連線');
        }

        return DB::transaction(function () use ($idempotencyKey, $amount, $result, $returned) {
            DB::table('mock_payouts')->insertOrIgnore([
                'idempotency_key' => $idempotencyKey,
                'provider_txn_id' => 'MPO'.Str::upper(Str::random(16)),
                'amount' => $amount,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('mock_payouts')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            // 已成功的撥款不再付款，只重送成功結果。
            if (in_array($row->status, ['succeeded', 'returned'], true)) {
                $this->dispatchPayoutEvent($idempotencyKey, $row->provider_txn_id, $amount, 'success');

                return $row->provider_txn_id;
            }

            DB::table('mock_payouts')->where('id', $row->id)->update([
                'status' => $result === 'failed' ? 'failed' : ($returned ? 'returned' : 'succeeded'),
                'attempts' => $row->attempts + 1,
                'updated_at' => now(),
            ]);
            $this->dispatchPayoutEvent($idempotencyKey, $row->provider_txn_id, $amount, $result);
            if ($returned) {
                $this->dispatchPayoutEvent($idempotencyKey, $row->provider_txn_id, $amount, 'returned');
            }

            return $row->provider_txn_id;
        });
    }

    public function queryStatus(string $idempotencyKey): ?string
    {
        return DB::table('mock_payouts')->where('idempotency_key', $idempotencyKey)->value('status');
    }

    /** 依付款頁選擇的情境送出 Webhook。 */
    public function simulatePayment(Order $order, string $scenario): void
    {
        $txnId = 'MTX'.Str::upper(Str::random(16));
        $event = fn (string $type, int $amount, array $extra = []) => $extra + [
            'event_id' => (string) Str::uuid(),
            'type' => $type,
            'order_no' => $order->order_no,
            'provider_txn_id' => $txnId,
            'amount' => $amount,
            'currency' => $order->currency,
        ];

        match ($scenario) {
            'failed' => DeliverMockWebhook::dispatch($event('payment.failed', $order->amount)),
            'declined' => DeliverMockWebhook::dispatch($event('payment.failed', $order->amount, ['failure_code' => 'card_declined'])),
            'delayed' => DeliverMockWebhook::dispatch($event('payment.succeeded', $order->amount))->delay(now()->addSeconds(config('topup.mock_delay_seconds'))),
            'duplicate' => (function () use ($event, $order) {
                $payload = $event('payment.succeeded', $order->amount);
                DeliverMockWebhook::dispatch($payload);
                DeliverMockWebhook::dispatch($payload);
            })(),
            'bad_signature' => DeliverMockWebhook::dispatch($event('payment.succeeded', $order->amount), true),
            'amount_mismatch' => DeliverMockWebhook::dispatch($event('payment.succeeded', $order->amount + 1)),
            default => DeliverMockWebhook::dispatch($event('payment.succeeded', $order->amount)),
        };
    }

    public static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('topup.mock_gateway_secret'));
    }

    private function dispatchPayoutEvent(string $key, string $txnId, int $amount, string $result): void
    {
        $job = DeliverMockWebhook::dispatch([
            'event_id' => (string) Str::uuid(),
            'type' => match ($result) {
                'failed' => 'payout.failed',
                'returned' => 'payout.returned',
                default => 'payout.succeeded',
            },
            'payout_key' => $key,
            'provider_txn_id' => $txnId,
            'amount' => $amount,
            'currency' => 'TWD',
        ])->afterCommit();

        // 退匯在撥款成功之後才通知。
        if ($result === 'delayed' || $result === 'returned') {
            $job->delay(now()->addSeconds(config('topup.mock_delay_seconds')));
        }
    }
}
