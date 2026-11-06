<?php

namespace App\Jobs;

use App\Game\GameGateway;
use App\Models\Anomaly;
use App\Models\Player;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** 同步到玩家端失敗時的補做工作。玩家端的停權 API 可重複呼叫。 */
class ApplyPlayerSuspension implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 8;

    public array $backoff = [2, 5, 15, 30, 60];

    public function __construct(public int $playerId) {}

    public function handle(GameGateway $game): void
    {
        $player = Player::findOrFail($this->playerId);
        if (! $player->isSuspended()) {
            return;
        }
        $game->suspendPlayer($player->game_player_id);
        Anomaly::resolveFor('Player', $player->id, '停權同步完成', null, 'suspension_sync_failed');
    }

    public function failed(Throwable $e): void
    {
        Anomaly::raise('suspension_sync_failed', 'Player', $this->playerId, '停權同步重試用盡：'.mb_substr($e->getMessage(), 0, 300));
    }
}
