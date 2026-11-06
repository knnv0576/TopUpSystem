<?php

namespace App\Services;

use App\Game\GameGateway;
use App\Game\InsufficientGamePoints;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Player;
use App\Models\PointAdjustment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 人工調整點數：初審發起、覆核核准。 */
class PointAdjustmentService
{
    public function __construct(private GameGateway $game) {}

    public function initiate(Player $player, AdminUser $admin, string $direction, int $points, string $reason): PointAdjustment
    {
        if (! $admin->isReviewer()) {
            throw new RuntimeException('只有初審可以發起人工調整');
        }
        if ($points < 1 || ! in_array($direction, ['add', 'deduct'], true)) {
            throw new RuntimeException('調整內容不正確');
        }

        return DB::transaction(function () use ($player, $admin, $direction, $points, $reason) {
            $locked = Player::whereKey($player->id)->lockForUpdate()->first();
            if ($locked->isSuspended()) {
                throw new RuntimeException('玩家停權中，不能發起調整');
            }

            $adjustment = PointAdjustment::create([
                'player_id' => $locked->id,
                'direction' => $direction,
                'points' => $points,
                'reason' => $reason,
                'initiator_id' => $admin->id,
            ]);
            AuditLog::record('adjustment.initiated', $adjustment, ['to' => 'pending_supervisor', 'points' => $points, 'note' => "$direction: $reason"], $admin);

            return $adjustment;
        });
    }

    /**
     * TOTP 已由控制器驗證。全程鎖定玩家列，與停權互斥。
     * 扣點餘額不足時回傳 false，調整會被標記為拒絕。
     */
    public function approve(PointAdjustment $adjustment, AdminUser $admin): bool
    {
        if (! $admin->isSupervisor()) {
            throw new RuntimeException('只有覆核者可以核准人工調整');
        }

        return DB::transaction(function () use ($adjustment, $admin) {
            $player = Player::whereKey($adjustment->player_id)->lockForUpdate()->first();
            $locked = PointAdjustment::whereKey($adjustment->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending_supervisor') {
                throw new RuntimeException('這筆調整已經處理過');
            }
            if ($player->isSuspended()) {
                throw new RuntimeException('玩家已停權，不能核准');
            }

            try {
                $this->game->adjustPoints($player->game_player_id, $locked->direction, $locked->points, "adjustment:{$locked->id}");
            } catch (InsufficientGamePoints) {
                $locked->update(['status' => 'rejected', 'supervisor_id' => $admin->id, 'reject_reason' => '可用點數不足']);
                AuditLog::record('adjustment.rejected', $locked, ['from' => 'pending_supervisor', 'to' => 'rejected', 'points' => $locked->points, 'note' => '可用點數不足'], $admin);

                return false;
            }

            $locked->update(['status' => 'applied', 'supervisor_id' => $admin->id]);
            AuditLog::record('adjustment.applied', $locked, ['from' => 'pending_supervisor', 'to' => 'applied', 'points' => $locked->points, 'note' => $locked->direction], $admin);

            return true;
        });
    }

    public function reject(PointAdjustment $adjustment, AdminUser $admin, string $reason): void
    {
        if (! $admin->isSupervisor()) {
            throw new RuntimeException('只有覆核者可以拒絕人工調整');
        }

        DB::transaction(function () use ($adjustment, $admin, $reason) {
            $updated = PointAdjustment::whereKey($adjustment->id)->where('status', 'pending_supervisor')
                ->update(['status' => 'rejected', 'supervisor_id' => $admin->id, 'reject_reason' => $reason, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new RuntimeException('這筆調整已經處理過');
            }
            AuditLog::record('adjustment.rejected', $adjustment, ['from' => 'pending_supervisor', 'to' => 'rejected', 'points' => $adjustment->points, 'note' => $reason], $admin);
        });
    }

    /** 停權時呼叫，必須在已鎖定玩家列的交易內。 */
    public function rejectPendingForSuspension(Player $player, AdminUser $admin): void
    {
        PointAdjustment::where('player_id', $player->id)->where('status', 'pending_supervisor')->lockForUpdate()->get()
            ->each(function (PointAdjustment $adjustment) use ($admin) {
                $adjustment->update(['status' => 'rejected', 'reject_reason' => '帳號停權，系統自動拒絕']);
                AuditLog::record('adjustment.rejected', $adjustment, ['from' => 'pending_supervisor', 'to' => 'rejected', 'points' => $adjustment->points, 'note' => '帳號停權，系統自動拒絕'], $admin);
            });
    }
}
