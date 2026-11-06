<?php

namespace App\Services;

use App\DemoGame\Models\RedPacket;
use App\DemoGame\Services\RedPacketService;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\RefundRequest;
use App\Payments\MockGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoTools
{
    private const RESET_TABLES = [
        'support_messages', 'support_tickets', 'player_bank_accounts', 'minigame_rounds', 'game_red_packets', 'game_point_ledger', 'game_wallets', 'game_players',
        'payouts', 'refund_requests', 'point_adjustments', 'point_grants', 'payments', 'orders',
        'gateway_events', 'anomalies', 'sso_tokens', 'api_nonces', 'mock_payouts', 'players',
        'jobs', 'failed_jobs',
    ];

    public function __construct(private RedPacketService $packets) {}

    /**
     * 清除玩家與交易資料，保留管理員帳號、方案與稽核紀錄。
     * 編號不重新起算，舊的稽核紀錄才不會指到新資料。
     */
    public function reset(AdminUser $admin): void
    {
        DB::transaction(function () use ($admin) {
            DB::statement('TRUNCATE TABLE '.implode(', ', self::RESET_TABLES).' CASCADE');
            AuditLog::record('demo.reset', null, ['note' => '重置測試資料'], $admin);
        });
        Cache::forget(MockGateway::NEXT_PAYOUT_CACHE_KEY);
        Cache::forget(MockGateway::NEXT_GAME_TOPUP_CACHE_KEY);
    }

    public function setNextGameTopup(string $result, AdminUser $admin): void
    {
        Cache::put(MockGateway::NEXT_GAME_TOPUP_CACHE_KEY, $result, now()->addDay());
        AuditLog::record('demo.next_game_topup', null, ['note' => "下一次遊戲內充值結果：$result"], $admin);
    }

    public function expirePacket(int $packetId, AdminUser $admin): void
    {
        $updated = RedPacket::whereKey($packetId)->where('status', 'sent')->update(['expires_at' => now()->subSecond()]);
        if ($updated !== 1) {
            throw new RuntimeException('找不到未領取的紅包');
        }
        $this->packets->expireDue();
        AuditLog::record('demo.expire_packet', null, ['note' => "紅包 #$packetId"], $admin);
    }

    public function makeRefundOverdue(int $refundId, AdminUser $admin): void
    {
        $updated = RefundRequest::whereKey($refundId)->whereIn('status', ['pending_review', 'pending_supervisor'])
            ->update(['review_started_at' => now()->subHours(config('topup.review_overdue_hours') + 1)]);
        if ($updated !== 1) {
            throw new RuntimeException('找不到審核中的申請');
        }
        AuditLog::record('demo.overdue_refund', null, ['note' => "申請 #$refundId"], $admin);
    }

    public function setNextPayout(string $result, AdminUser $admin): void
    {
        Cache::put(MockGateway::NEXT_PAYOUT_CACHE_KEY, $result, now()->addDay());
        AuditLog::record('demo.next_payout', null, ['note' => $result], $admin);
    }
}
