<?php

namespace App\Services;

use App\Game\GameGateway;
use App\Jobs\GrantPoints;
use App\Models\AdminUser;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Player;
use App\Models\PointGrant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FulfillmentService
{
    public function __construct(private GameGateway $game) {}

    /** 冪等：玩家端以 grant 的冪等鍵去重，重試不會重複入點。 */
    public function grant(int $grantId): void
    {
        $grant = PointGrant::with(['order', 'player'])->find($grantId);
        if (! $grant || $grant->status === 'granted') {
            return;
        }

        if ($grant->player->isSuspended()) {
            DB::transaction(function () use ($grant) {
                PointGrant::whereKey($grant->id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'held_suspended']);
                Anomaly::raise('grant_held_suspended', 'PointGrant', $grant->id, "訂單 {$grant->order->order_no} 玩家已停權，解除後由覆核者手動補入 {$grant->points} 點");
                AuditLog::record('grant.held_suspended', $grant, ['to' => 'held_suspended', 'points' => $grant->points]);
            });

            return;
        }

        $this->game->grantPoints($grant->player->game_player_id, $grant->points, $grant->idempotencyKey(), "充值訂單 {$grant->order->order_no}");

        DB::transaction(function () use ($grant) {
            $updated = PointGrant::whereKey($grant->id)->where('status', '!=', 'granted')->update(['status' => 'granted', 'granted_at' => now()]);
            if ($updated === 1) {
                Order::whereKey($grant->order_id)->update(['status' => 'fulfilled']);
                AuditLog::record('grant.granted', $grant, ['to' => 'granted', 'points' => $grant->points]);
                Anomaly::resolveFor('PointGrant', $grant->id, '入點完成');
            }
        });
    }

    public function markFailed(int $grantId, string $error): void
    {
        DB::transaction(function () use ($grantId, $error) {
            $updated = PointGrant::whereKey($grantId)->where('status', 'pending')->update(['status' => 'failed']);
            if ($updated === 1) {
                Anomaly::raise('grant_failed', 'PointGrant', $grantId, '自動重試用盡：'.mb_substr($error, 0, 300));
                AuditLog::record('grant.failed', PointGrant::find($grantId), ['from' => 'pending', 'to' => 'failed']);
            }
        });
    }

    /** 覆核者在異常清單重試失敗的入點，或在解除停權後補入暫扣的入點。 */
    public function retry(PointGrant $grant, AdminUser $admin): void
    {
        DB::transaction(function () use ($grant, $admin) {
            $player = Player::whereKey($grant->player_id)->lockForUpdate()->first();
            if ($player->isSuspended()) {
                throw new RuntimeException('玩家停權中，解除後才能補入點');
            }

            $from = $grant->status;
            $updated = PointGrant::whereKey($grant->id)->whereIn('status', ['failed', 'held_suspended'])->update(['status' => 'pending']);
            if ($updated !== 1) {
                throw new RuntimeException('這筆入點不需要重試');
            }

            AuditLog::record('grant.retry', $grant, ['from' => $from, 'to' => 'pending', 'points' => $grant->points], $admin);
            Anomaly::resolveFor('PointGrant', $grant->id, '已由管理員重新入點', $admin->id);
            GrantPoints::dispatch($grant->id)->afterCommit();
        });
    }
}
