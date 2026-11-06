<?php

namespace App\Jobs;

use App\Services\FulfillmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class GrantPoints implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public array $backoff = [5, 15, 30, 60];

    public function __construct(public int $grantId) {}

    public function handle(FulfillmentService $service): void
    {
        $service->grant($this->grantId);
    }

    public function failed(Throwable $e): void
    {
        app(FulfillmentService::class)->markFailed($this->grantId, $e->getMessage());
    }
}
