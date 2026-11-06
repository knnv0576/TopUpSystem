<?php

namespace App\DemoGame\Jobs;

use App\DemoGame\Services\TopupClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/** 玩家改密碼時，立即通知充值端失敗後的補做工作。 */
class RevokeShopSessions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 8;

    public array $backoff = [2, 5, 15, 30, 60];

    public function __construct(public int $playerId) {}

    public function handle(TopupClient $topup): void
    {
        $topup->revokeSessions($this->playerId);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('security')->error('player.shop_session_revoke_failed', ['player_id' => $this->playerId]);
    }
}
