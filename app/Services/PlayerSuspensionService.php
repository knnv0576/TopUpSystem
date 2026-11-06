<?php

namespace App\Services;

use App\Game\GameGateway;
use App\Jobs\ApplyPlayerSuspension;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PlayerSuspensionService
{
    public function __construct(
        private GameGateway $game,
        private RefundService $refunds,
        private PointAdjustmentService $adjustments,
    ) {}

    /** TOTP 已由控制器驗證。 */
    public function suspend(Player $player, AdminUser $admin, string $reason): void
    {
        $this->assertSupervisor($admin);

        DB::transaction(function () use ($player, $admin, $reason) {
            $locked = Player::whereKey($player->id)->lockForUpdate()->first();
            if ($locked->isSuspended()) {
                throw new RuntimeException('玩家已經停權');
            }

            $locked->update(['suspended_at' => now(), 'suspend_reason' => $reason]);
            AuditLog::record('player.suspended', $locked, ['to' => 'suspended', 'note' => $reason], $admin);

            $this->refunds->rejectInReviewForSuspension($locked, $admin);
            $this->adjustments->rejectPendingForSuspension($locked, $admin);
        });

        // 優先立即同步；失敗時交給可重試的工作。充值端此時已經阻擋 SSO、入點與核准。
        try {
            $this->game->suspendPlayer($player->game_player_id);
        } catch (Throwable $e) {
            report($e);
            ApplyPlayerSuspension::dispatch($player->id);
        }
    }

    /** 解除只恢復登入；已拒絕的申請、調整與已退回的紅包都不會復原。 */
    public function unsuspend(Player $player, AdminUser $admin, string $reason): void
    {
        $this->assertSupervisor($admin);

        if (! $player->fresh()->isSuspended()) {
            throw new RuntimeException('玩家沒有停權');
        }

        // 先解除玩家端；失敗時充值端仍維持停權，可以再試一次。
        $this->game->unsuspendPlayer($player->game_player_id);

        DB::transaction(function () use ($player, $admin, $reason) {
            $locked = Player::whereKey($player->id)->lockForUpdate()->first();
            $locked->update(['suspended_at' => null, 'suspend_reason' => null]);
            AuditLog::record('player.unsuspended', $locked, ['from' => 'suspended', 'note' => $reason], $admin);
        });
    }

    private function assertSupervisor(AdminUser $admin): void
    {
        if (! $admin->isSupervisor()) {
            throw new RuntimeException('只有覆核者可以停權或解除');
        }
    }
}
