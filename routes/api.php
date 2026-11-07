<?php

use App\DemoGame\Http\InternalApiController;
use App\Http\Controllers\TopupApiController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// 這些路由只經由 Docker 內部 port 提供；nginx 的對外 HTTPS 會回 404。

Route::post('webhooks/payments/mock', WebhookController::class)->middleware('throttle:300,1');

// 充值端 → 玩家端
Route::prefix('api/game')->middleware(['throttle:600,1', 'api.signature:game_api_secret'])->group(function () {
    Route::post('players/lookup', [InternalApiController::class, 'lookup']);
    Route::post('players/{player}/grant', [InternalApiController::class, 'grant']);
    Route::post('players/{player}/freeze', [InternalApiController::class, 'freeze']);
    Route::post('players/{player}/unfreeze', [InternalApiController::class, 'unfreeze']);
    Route::post('players/{player}/deduct-frozen', [InternalApiController::class, 'deductFrozen']);
    Route::post('players/{player}/return-deducted', [InternalApiController::class, 'returnDeducted']);
    Route::post('players/{player}/adjust', [InternalApiController::class, 'adjust']);
    Route::post('players/{player}/suspend', [InternalApiController::class, 'suspend']);
    Route::post('players/{player}/unsuspend', [InternalApiController::class, 'unsuspend']);
});

// 小遊戲 → 玩家端
Route::prefix('api/points')->middleware(['throttle:600,1', 'api.signature:game_api_secret'])->group(function () {
    Route::post('debit', [InternalApiController::class, 'debit']);
    Route::post('debit/void', [InternalApiController::class, 'voidDebit']);
    Route::post('credit', [InternalApiController::class, 'credit']);
});

// 玩家端 → 充值端
Route::prefix('api/topup')->middleware(['throttle:600,1', 'api.signature:topup_api_secret'])->group(function () {
    Route::post('refund-requests', [TopupApiController::class, 'submitRefund']);
    Route::post('players/{gamePlayerId}/refund-requests', [TopupApiController::class, 'refundRequests'])->whereNumber('gamePlayerId');
    Route::post('players/{gamePlayerId}/bank-accounts', [TopupApiController::class, 'bankAccounts'])->whereNumber('gamePlayerId');
    Route::post('players/{gamePlayerId}/bank-accounts/add', [TopupApiController::class, 'addBankAccount'])->whereNumber('gamePlayerId');
    Route::post('players/{gamePlayerId}/bank-accounts/{accountId}/delete', [TopupApiController::class, 'deleteBankAccount'])->whereNumber(['gamePlayerId', 'accountId']);
    Route::post('players/{gamePlayerId}/revoke-sessions', [TopupApiController::class, 'revokeSessions'])->whereNumber('gamePlayerId');
    Route::post('players/{gamePlayerId}/orders', [TopupApiController::class, 'orders'])->whereNumber('gamePlayerId');
    Route::post('packages', [TopupApiController::class, 'packages']);
    Route::post('players/{gamePlayerId}/game-orders', [TopupApiController::class, 'createGameOrder'])->whereNumber('gamePlayerId');
    Route::post('players/{gamePlayerId}/game-orders/{orderNo}', [TopupApiController::class, 'gameOrder'])->whereNumber('gamePlayerId');
    Route::post('players/{gamePlayerId}/game-orders/{orderNo}/pay', [TopupApiController::class, 'payGameOrder'])->whereNumber('gamePlayerId');
});
