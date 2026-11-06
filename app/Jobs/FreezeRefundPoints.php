<?php

namespace App\Jobs;

use App\Models\Anomaly;
use App\Services\RefundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class FreezeRefundPoints implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public array $backoff = [2, 5, 15, 30];

    public function __construct(public int $requestId) {}

    public function handle(RefundService $service): void
    {
        $service->freeze($this->requestId);
    }

    public function failed(Throwable $e): void
    {
        Anomaly::raise('freeze_failed', 'RefundRequest', $this->requestId, '凍結重試用盡，排程會繼續補做：'.mb_substr($e->getMessage(), 0, 300));
    }
}
