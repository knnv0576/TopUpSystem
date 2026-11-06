<?php

namespace App\Jobs;

use App\Payments\MockGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/** 模擬金流把事件以 HTTP 送到我方 Webhook。 */
class DeliverMockWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public array $backoff = [2, 5, 10, 30];

    public function __construct(public array $payload, public bool $badSignature = false) {}

    public function handle(): void
    {
        $body = json_encode($this->payload);
        $signature = $this->badSignature ? str_repeat('0', 64) : MockGateway::sign($body);

        $response = Http::withHeaders(['X-Mock-Signature' => $signature])
            ->withBody($body, 'application/json')
            ->timeout(10)
            ->post(rtrim(config('topup.internal_api_url'), '/').'/webhooks/payments/mock');

        // 錯誤簽章的情境預期被拒，不重試。
        if ($response->serverError()) {
            $response->throw();
        }
    }
}
