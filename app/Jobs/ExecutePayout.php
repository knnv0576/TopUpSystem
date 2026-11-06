<?php

namespace App\Jobs;

use App\Services\RefundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class ExecutePayout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public array $backoff = [5, 15, 30];

    public function __construct(public int $requestId) {}

    public function handle(RefundService $service): void
    {
        $service->execute($this->requestId);
    }

    public function failed(Throwable $e): void
    {
        app(RefundService::class)->markPayoutFailed($this->requestId, $e->getMessage());
    }
}
