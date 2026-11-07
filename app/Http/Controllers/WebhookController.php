<?php

namespace App\Http\Controllers;

use App\Payments\PaymentGateway;
use App\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController
{
    public function __invoke(Request $request, PaymentGateway $gateway, PaymentWebhookService $service): JsonResponse
    {
        $event = $gateway->parseWebhook($request);
        if ($event === null) {
            // 內容未通過驗簽，不可信：只記下聲稱的編號（截斷長度），不記完整內容。
            $claimed = fn (string $key) => is_scalar($value = $request->json($key)) ? mb_substr((string) $value, 0, 64) : null;
            Log::channel('security')->warning('webhook.rejected', [
                'provider' => $gateway->name(),
                'reason' => 'invalid_signature_or_payload',
                'claimed_order_no' => $claimed('order_no'),
                'claimed_event_id' => $claimed('event_id'),
                'claimed_type' => $claimed('type'),
                'ip' => $request->server('REMOTE_ADDR'),
            ]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $service->handle($event, $request->json()->all());

        return response()->json(['ok' => true]);
    }
}
