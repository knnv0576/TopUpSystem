<?php

namespace App\Jobs;

use App\Game\GameGateway;
use App\Models\Anomaly;
use App\Models\RefundRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class UnfreezeRefundPoints implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public array $backoff = [2, 5, 15, 30];

    public function __construct(public int $requestId) {}

    public function handle(GameGateway $game): void
    {
        $request = RefundRequest::with('player')->findOrFail($this->requestId);
        $game->unfreezePoints($request->player->game_player_id, $request->points, $request->ref());
        Anomaly::resolveFor('RefundRequest', $request->id, '解凍完成', null, 'unfreeze_failed');
    }

    public function failed(Throwable $e): void
    {
        Anomaly::raise('unfreeze_failed', 'RefundRequest', $this->requestId, '解凍重試用盡：'.mb_substr($e->getMessage(), 0, 300));
    }
}
