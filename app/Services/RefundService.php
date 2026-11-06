<?php

namespace App\Services;

use App\Game\GameGateway;
use App\Game\InsufficientGamePoints;
use App\Jobs\ExecutePayout;
use App\Jobs\FreezeRefundPoints;
use App\Jobs\UnfreezeRefundPoints;
use App\Models\AdminUser;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\Player;
use App\Models\RefundRequest;
use App\Payments\PaymentGateway;
use App\Support\PlayerMessages;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 退款狀態機。所有轉換都在伺服器端檢查，並用條件更新保證只成功一次。
 */
class RefundService
{
    public function __construct(private GameGateway $game, private PaymentGateway $gateway) {}

    /**
     * 玩家端送出申請。相同 game_request_id 回傳原申請。
     *
     * @throws RuntimeException 不符合申請規則
     */
    public function submit(string $gameRequestId, int $gamePlayerId, int $points, string $bankCode, string $account): RefundRequest
    {
        $existing = RefundRequest::where('game_request_id', $gameRequestId)->first();
        if ($existing) {
            return $existing;
        }

        if ($points < config('topup.refund_min_points') || $points % config('topup.refund_points_step') !== 0) {
            throw new RuntimeException('退款點數須為 100 的倍數，單次至少 10,000 點');
        }

        $player = Player::forGame($gamePlayerId);

        try {
            return DB::transaction(function () use ($gameRequestId, $player, $points, $bankCode, $account) {
                $player = Player::whereKey($player->id)->lockForUpdate()->first();
                if ($player->isSuspended()) {
                    throw new RuntimeException(PlayerMessages::SUSPENDED);
                }

                $request = RefundRequest::create([
                    'game_request_id' => $gameRequestId,
                    'player_id' => $player->id,
                    'points' => $points,
                    'amount' => intdiv($points, config('topup.points_per_twd')),
                    'status' => 'pending_freeze',
                    'bank_code' => $bankCode,
                    'account_last4' => substr($account, -4),
                    'account_encrypted' => $account,
                ]);
                Payout::create([
                    'refund_request_id' => $request->id,
                    'amount' => $request->amount,
                    'idempotency_key' => "refund-{$request->id}",
                ]);

                AuditLog::record('refund.submitted', $request, ['to' => 'pending_freeze', 'amount' => $request->amount, 'points' => $points, 'actor_type' => 'player', 'actor' => (string) $player->game_player_id]);
                FreezeRefundPoints::dispatch($request->id)->afterCommit();

                return $request;
            });
        } catch (UniqueConstraintViolationException $e) {
            $same = RefundRequest::where('game_request_id', $gameRequestId)->first();
            if ($same) {
                return $same;
            }
            throw new RuntimeException('已有一筆進行中的退款申請');
        }
    }

    /** 由工作呼叫。凍結完成才進入審核佇列。 */
    public function freeze(int $requestId): void
    {
        $request = RefundRequest::with('player')->find($requestId);
        if (! $request || $request->status !== 'pending_freeze') {
            return;
        }

        try {
            $this->game->freezePoints($request->player->game_player_id, $request->points, $request->ref());
        } catch (InsufficientGamePoints) {
            DB::transaction(function () use ($request) {
                if ($this->transition($request, ['pending_freeze'], 'rejected', ['reject_reason' => '可用點數不足', 'closed_at' => now(), 'account_encrypted' => null])) {
                    AuditLog::record('refund.rejected', $request, ['from' => 'pending_freeze', 'to' => 'rejected', 'points' => $request->points, 'note' => '可用點數不足']);
                }
            });

            return;
        }

        DB::transaction(function () use ($request) {
            if ($this->transition($request, ['pending_freeze'], 'pending_review', ['review_started_at' => now()])) {
                AuditLog::record('refund.frozen', $request, ['from' => 'pending_freeze', 'to' => 'pending_review', 'points' => $request->points]);
                Anomaly::resolveFor('RefundRequest', $request->id, '凍結完成', null, 'freeze_failed');
            }
        });
    }

    public function reviewApprove(RefundRequest $request, AdminUser $admin): void
    {
        $this->assertRole($admin, AdminUser::REVIEWER);

        DB::transaction(function () use ($request, $admin) {
            if (! $this->transition($request, ['pending_review'], 'pending_supervisor', ['reviewer_id' => $admin->id])) {
                throw new RuntimeException('這筆申請目前不能初審');
            }
            AuditLog::record('refund.review_approved', $request, ['from' => 'pending_review', 'to' => 'pending_supervisor', 'amount' => $request->amount, 'points' => $request->points], $admin);
        });
    }

    public function reviewReject(RefundRequest $request, AdminUser $admin, string $reason): void
    {
        $this->assertRole($admin, AdminUser::REVIEWER);
        $this->reject($request, $admin, $reason, 'pending_review', ['reviewer_id' => $admin->id]);
    }

    public function supervisorReject(RefundRequest $request, AdminUser $admin, string $reason): void
    {
        $this->assertRole($admin, AdminUser::SUPERVISOR);
        $this->reject($request, $admin, $reason, 'pending_supervisor', ['supervisor_id' => $admin->id]);
    }

    /** 覆核核准。TOTP 已由控制器驗證。先鎖玩家再鎖申請，與停權互斥。 */
    public function supervisorApprove(RefundRequest $request, AdminUser $admin): void
    {
        $this->assertRole($admin, AdminUser::SUPERVISOR);

        DB::transaction(function () use ($request, $admin) {
            $player = Player::whereKey($request->player_id)->lockForUpdate()->first();
            if ($player->isSuspended()) {
                throw new RuntimeException('玩家已停權，不能核准');
            }

            $locked = RefundRequest::whereKey($request->id)->lockForUpdate()->first();
            if ($locked->reviewer_id === $admin->id) {
                throw new RuntimeException('同一人不能處理兩層審核');
            }
            if (! $this->transition($locked, ['pending_supervisor'], 'approved', ['supervisor_id' => $admin->id])) {
                throw new RuntimeException('這筆申請目前不能覆核');
            }

            AuditLog::record('refund.approved', $locked, ['from' => 'pending_supervisor', 'to' => 'approved', 'amount' => $locked->amount, 'points' => $locked->points], $admin);
            ExecutePayout::dispatch($locked->id)->afterCommit();
        });
    }

    /** 由工作呼叫：扣點 → 撥款。每一步都冪等，可以從任何一步重新開始。 */
    public function execute(int $requestId): void
    {
        $request = RefundRequest::with(['player', 'payout'])->find($requestId);
        if (! $request) {
            return;
        }

        if ($request->status === 'approved') {
            $this->game->deductFrozenPoints($request->player->game_player_id, $request->points, $request->ref());
            DB::transaction(function () use ($request) {
                if ($this->transition($request, ['approved'], 'points_deducted')) {
                    AuditLog::record('refund.points_deducted', $request, ['from' => 'approved', 'to' => 'points_deducted', 'points' => $request->points]);
                }
            });
            $request->refresh();
        }

        if ($request->status === 'points_deducted') {
            DB::transaction(function () use ($request) {
                if ($this->transition($request, ['points_deducted'], 'paying_out')) {
                    AuditLog::record('refund.paying_out', $request, ['from' => 'points_deducted', 'to' => 'paying_out', 'amount' => $request->amount]);
                }
            });
            $request->refresh();
        }

        if ($request->status !== 'paying_out') {
            return;
        }

        $payout = $request->payout;
        $payout->increment('attempts');
        $txnId = $this->gateway->payout($payout->idempotency_key, $request->amount, $request->bank_code, (string) $request->account_encrypted);
        Payout::whereKey($payout->id)->whereNull('provider_txn_id')->update(['provider_txn_id' => $txnId]);
    }

    /** 自動重試用盡。 */
    public function markPayoutFailed(int $requestId, string $error): void
    {
        DB::transaction(function () use ($requestId, $error) {
            $request = RefundRequest::whereKey($requestId)->lockForUpdate()->first();
            if ($request && $this->transition($request, ['approved', 'points_deducted', 'paying_out'], 'payout_failed')) {
                $this->flagPayoutFailure($request, '自動重試用盡：'.mb_substr($error, 0, 300));
            }
        });
    }

    public function payoutSucceeded(array $event): void
    {
        $payout = Payout::where('idempotency_key', $event['payout_key'])->first();
        if (! $payout) {
            return;
        }

        $request = RefundRequest::whereKey($payout->refund_request_id)->lockForUpdate()->first();
        $payout->update(['status' => 'succeeded', 'provider_txn_id' => $event['provider_txn_id']]);

        if (in_array($request->status, ['closing', 'closed_points_returned'], true)) {
            Anomaly::raise('late_payout_result', 'RefundRequest', $request->id, "申請已結案還點，卻收到撥款成功通知（{$event['provider_txn_id']}）");

            return;
        }

        $from = $request->status;
        if ($this->transition($request, ['paying_out', 'payout_failed'], 'paid_out', ['closed_at' => now(), 'account_encrypted' => null])) {
            AuditLog::record('refund.paid_out', $request, ['from' => $from, 'to' => 'paid_out', 'amount' => $request->amount, 'points' => $request->points]);
            Anomaly::resolveFor('RefundRequest', $request->id, '撥款成功');
        }
    }

    public function payoutFailed(array $event): void
    {
        $payout = Payout::where('idempotency_key', $event['payout_key'])->first();
        if (! $payout) {
            return;
        }

        $request = RefundRequest::whereKey($payout->refund_request_id)->lockForUpdate()->first();
        if ($this->transition($request, ['paying_out'], 'payout_failed')) {
            $payout->update(['status' => 'failed', 'provider_txn_id' => $event['provider_txn_id']]);
            $this->flagPayoutFailure($request, '模擬金流回報撥款失敗');
        }
    }

    /** 覆核者重試：回到 approved 後重新執行。扣點與撥款都冪等，不會重複。 */
    public function retryPayout(RefundRequest $request, AdminUser $admin): void
    {
        $this->assertRole($admin, AdminUser::SUPERVISOR);

        DB::transaction(function () use ($request, $admin) {
            if (! $this->transition($request, ['payout_failed'], 'approved')) {
                throw new RuntimeException('這筆申請目前不能重試');
            }
            AuditLog::record('refund.retry', $request, ['from' => 'payout_failed', 'to' => 'approved', 'amount' => $request->amount], $admin);
            Anomaly::resolveFor('RefundRequest', $request->id, '已由管理員重試', $admin->id, 'payout_failed');
            ExecutePayout::dispatch($request->id)->afterCommit();
        });
    }

    /** 覆核者結案還點。TOTP 已由控制器驗證。已成功的撥款不能結案。 */
    public function closeWithReturn(RefundRequest $request, AdminUser $admin, string $reason): void
    {
        $this->assertRole($admin, AdminUser::SUPERVISOR);

        if ($this->gateway->queryStatus($request->payout->idempotency_key) === 'succeeded') {
            throw new RuntimeException('模擬金流顯示已撥款成功，不能結案還點');
        }

        DB::transaction(function () use ($request, $admin, $reason) {
            if (! $this->transition($request, ['payout_failed'], 'closing', ['reject_reason' => $reason])) {
                throw new RuntimeException('這筆申請目前不能結案');
            }
            AuditLog::record('refund.closing', $request, ['from' => 'payout_failed', 'to' => 'closing', 'points' => $request->points, 'note' => $reason], $admin);
        });

        $this->finishClosing($request->id);
    }

    /** 冪等；失敗時停在 closing，由排程補做。 */
    public function finishClosing(int $requestId): void
    {
        $request = RefundRequest::with('player')->find($requestId);
        if (! $request || $request->status !== 'closing') {
            return;
        }

        $this->game->returnDeductedPoints($request->player->game_player_id, $request->points, $request->ref());

        DB::transaction(function () use ($request) {
            if ($this->transition($request, ['closing'], 'closed_points_returned', ['closed_at' => now(), 'account_encrypted' => null])) {
                AuditLog::record('refund.closed_points_returned', $request, ['from' => 'closing', 'to' => 'closed_points_returned', 'points' => $request->points]);
                Anomaly::resolveFor('RefundRequest', $request->id, '已結案還點');
            }
        });
    }

    /** 停權時呼叫，必須在已鎖定玩家列的交易內。 */
    public function rejectInReviewForSuspension(Player $player, AdminUser $admin): void
    {
        RefundRequest::where('player_id', $player->id)->whereIn('status', RefundRequest::IN_REVIEW)->lockForUpdate()->get()
            ->each(function (RefundRequest $request) use ($admin) {
                $from = $request->status;
                if ($this->transition($request, [$from], 'rejected', ['reject_reason' => '帳號停權，系統自動拒絕', 'closed_at' => now(), 'account_encrypted' => null])) {
                    AuditLog::record('refund.rejected', $request, ['from' => $from, 'to' => 'rejected', 'points' => $request->points, 'note' => '帳號停權，系統自動拒絕'], $admin);
                    UnfreezeRefundPoints::dispatch($request->id)->afterCommit();
                }
            });
    }

    /** 排程補做：處理卡住的申請。 */
    public function reconcile(): void
    {
        $stuck = now()->subMinutes(10);

        RefundRequest::where('status', 'pending_freeze')->where('updated_at', '<', $stuck)->pluck('id')
            ->each(fn ($id) => FreezeRefundPoints::dispatch($id));

        RefundRequest::whereIn('status', ['approved', 'points_deducted'])->where('updated_at', '<', $stuck)->pluck('id')
            ->each(fn ($id) => ExecutePayout::dispatch($id));

        RefundRequest::where('status', 'closing')->where('updated_at', '<', $stuck)->pluck('id')
            ->each(function ($id) {
                try {
                    $this->finishClosing($id);
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        // 撥款中太久：Webhook 可能遺失，向金流查詢實際結果。
        RefundRequest::with('payout')->where('status', 'paying_out')->where('updated_at', '<', $stuck)->get()
            ->each(function (RefundRequest $request) {
                $status = $this->gateway->queryStatus($request->payout->idempotency_key);
                $event = ['payout_key' => $request->payout->idempotency_key, 'provider_txn_id' => $request->payout->provider_txn_id];

                match ($status) {
                    'succeeded' => DB::transaction(fn () => $this->payoutSucceeded($event)),
                    'failed' => DB::transaction(fn () => $this->payoutFailed($event)),
                    default => ExecutePayout::dispatch($request->id),
                };
            });
    }

    private function reject(RefundRequest $request, AdminUser $admin, string $reason, string $from, array $attributes): void
    {
        DB::transaction(function () use ($request, $admin, $reason, $from, $attributes) {
            $changes = $attributes + ['reject_reason' => $reason, 'closed_at' => now(), 'account_encrypted' => null];
            if (! $this->transition($request, [$from], 'rejected', $changes)) {
                throw new RuntimeException('這筆申請目前不能拒絕');
            }
            AuditLog::record('refund.rejected', $request, ['from' => $from, 'to' => 'rejected', 'points' => $request->points, 'note' => $reason], $admin);
            UnfreezeRefundPoints::dispatch($request->id)->afterCommit();
        });
    }

    private function flagPayoutFailure(RefundRequest $request, string $detail): void
    {
        Anomaly::raise('payout_failed', 'RefundRequest', $request->id, $detail);
        AuditLog::record('refund.payout_failed', $request, ['to' => 'payout_failed', 'amount' => $request->amount, 'note' => $detail]);
    }

    /** 條件更新：只有目前狀態符合時才轉換。 */
    private function transition(RefundRequest $request, array $from, string $to, array $attributes = []): bool
    {
        // 經由模型屬性寫入，加密欄位才會正確處理；再用條件更新保證只成功一次。
        $model = new RefundRequest;
        $model->forceFill($attributes);
        $values = array_merge($model->getAttributes(), ['status' => $to, 'updated_at' => now()]);

        $updated = RefundRequest::whereKey($request->id)->whereIn('status', $from)->update($values);
        if ($updated === 1) {
            $request->refresh();
        }

        return $updated === 1;
    }

    private function assertRole(AdminUser $admin, string $role): void
    {
        if ($admin->role !== $role) {
            throw new RuntimeException('這個角色不能執行此操作');
        }
    }
}
