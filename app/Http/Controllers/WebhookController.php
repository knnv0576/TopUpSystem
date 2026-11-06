<?php

namespace App\Http\Controllers;

use App\Payments\PaymentGateway;
use App\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController
{
    public function __invoke(Request $request, PaymentGateway $gateway, PaymentWebhookService $service): JsonResponse
    {
        $event = $gateway->parseWebhook($request);
        if ($event === null) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $service->handle($event, $request->json()->all());

        return response()->json(['ok' => true]);
    }
}
