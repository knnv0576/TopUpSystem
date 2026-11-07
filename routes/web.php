<?php

use App\DemoGame\Http\AuthController as GameAuthController;
use App\DemoGame\Http\GameController;
use App\DemoGame\Http\RedPacketController;
use App\DemoGame\Http\RefundController as GameRefundController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AnomalyController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DemoController;
use App\Http\Controllers\Admin\MiniGameController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PlayerController;
use App\Http\Controllers\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Admin\SupportController as AdminSupportController;
use App\Http\Controllers\MockPayController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\SupportController;
use App\MiniGame\Http\WheelController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/game');

// 玩家端
Route::prefix('game')->name('game.')->group(function () {
    Route::get('login', [GameAuthController::class, 'showLogin'])->name('login');
    Route::post('login', [GameAuthController::class, 'login'])->middleware('throttle:auth');
    Route::get('register', [GameAuthController::class, 'showRegister'])->name('register');
    Route::post('register', [GameAuthController::class, 'register'])->middleware('throttle:auth');

    Route::middleware('game.player')->group(function () {
        Route::post('logout', [GameAuthController::class, 'logout'])->name('logout');
        Route::get('password', [GameAuthController::class, 'showPassword'])->name('password');
        Route::post('password', [GameAuthController::class, 'changePassword'])->middleware('throttle:auth')->name('password.update');
        Route::get('/', [GameController::class, 'dashboard'])->name('dashboard');
        Route::post('task', [GameController::class, 'task'])->name('task');
        Route::post('nickname', [GameController::class, 'nickname'])->name('nickname');
        Route::get('ledger', [GameController::class, 'ledger'])->name('ledger');
        Route::get('orders', [GameController::class, 'orders'])->name('orders');
        Route::post('go-shop', [GameController::class, 'goShop'])->name('go-shop');
        // 客服在充值網站；這裡發單次 token 後自動轉過去。
        Route::get('support', [GameController::class, 'goSupport'])->name('support');

        Route::get('red-packets', [RedPacketController::class, 'index'])->name('red-packets');
        Route::post('red-packets/confirm', [RedPacketController::class, 'confirm'])->name('red-packets.confirm');
        Route::post('red-packets', [RedPacketController::class, 'send'])->name('red-packets.send');
        Route::post('red-packets/{packet}/claim', [RedPacketController::class, 'claim'])->whereNumber('packet')->name('red-packets.claim');

        Route::get('refunds', [GameRefundController::class, 'index'])->name('refunds');
        Route::post('refunds', [GameRefundController::class, 'submit'])->name('refunds.submit');
        Route::post('refunds/accounts', [GameRefundController::class, 'addAccount'])->middleware('throttle:10,10,bank-account')->name('refunds.accounts.store');
        Route::post('refunds/accounts/{account}/delete', [GameRefundController::class, 'deleteAccount'])->whereNumber('account')->name('refunds.accounts.destroy');

        Route::get('wheel', [WheelController::class, 'index'])->name('wheel');
        Route::get('wheel/rounds', [WheelController::class, 'rounds'])->name('wheel.rounds');
        // 第三個參數是計數器前綴：沒有它的話，同一位玩家的這些路由會共用計數，查詢狀態也會吃掉轉盤的額度。
        Route::post('wheel/topups', [WheelController::class, 'createTopup'])->middleware('throttle:20,1,wheel-topup')->name('wheel.topups');
        Route::get('wheel/topups/{orderNo}', [WheelController::class, 'showTopup'])->middleware('throttle:120,1,wheel-topup-status')->name('wheel.topups.show');
        // 與模擬付款頁相同：送出付款每分鐘最多 20 次。
        Route::post('wheel/topups/{orderNo}/pay', [WheelController::class, 'payTopup'])->middleware('throttle:20,1,wheel-topup-pay')->name('wheel.topups.pay');
        Route::post('wheel/{publicId}/spin', [WheelController::class, 'spin'])->whereUuid('publicId')->middleware('throttle:30,1,wheel-spin')->name('wheel.spin');
        Route::get('wheel/{publicId}', [WheelController::class, 'show'])->whereUuid('publicId')->middleware('throttle:120,1,wheel-status')->name('wheel.show');
    });
});

// 充值網站
Route::post('shop/sso', [ShopController::class, 'sso'])->middleware('throttle:auth')->name('shop.sso');
Route::prefix('shop')->name('shop.')->middleware('shop.player')->group(function () {
    Route::get('/', [ShopController::class, 'index'])->name('index');
    Route::post('orders', [ShopController::class, 'createOrder'])->name('orders.create');
    Route::get('orders/{orderNo}', [ShopController::class, 'showOrder'])->name('orders.show');
    Route::get('back-to-game', [ShopController::class, 'backToGame'])->name('back-to-game');

    Route::get('support', [SupportController::class, 'index'])->name('support.index');
    Route::post('support', [SupportController::class, 'store'])->middleware('throttle:5,10,support-create')->name('support.store');
    Route::get('support/{ticket}', [SupportController::class, 'show'])->name('support.show');
    Route::post('support/{ticket}/reply', [SupportController::class, 'reply'])->middleware('throttle:20,10,support-reply')->name('support.reply');
});

// 模擬金流付款頁
Route::middleware('shop.player')->group(function () {
    Route::get('mock-pay/{orderNo}', [MockPayController::class, 'show'])->name('mock.pay.show');
    Route::post('mock-pay/{orderNo}', [MockPayController::class, 'pay'])->middleware('throttle:20,1,mock-pay')->name('mock.pay.submit');
});

// 管理後台
Route::prefix('admin')->name('admin.')->middleware('admin.ip')->group(function () {
    Route::get('login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:auth');
    Route::get('totp', [AdminAuthController::class, 'showTotp'])->name('totp');
    Route::post('totp', [AdminAuthController::class, 'verifyTotp'])->middleware('throttle:auth');

    Route::middleware('admin')->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
        // 保留提示訊息：這裡只是轉址，訊息要留給下一頁顯示。
        Route::get('/', function () {
            session()->reflash();

            return redirect()->route('admin.refunds');
        })->name('home');
        Route::get('password', [AdminAuthController::class, 'showPassword'])->name('password');
        Route::post('password', [AdminAuthController::class, 'changePassword'])->middleware('throttle:auth')->name('password.update');

        Route::get('refunds', [AdminRefundController::class, 'index'])->name('refunds');
        Route::get('refunds/{refund}', [AdminRefundController::class, 'show'])->name('refunds.show');
        Route::get('players', [PlayerController::class, 'index'])->name('players');
        Route::get('players/{player}', [PlayerController::class, 'show'])->name('players.show');
        Route::get('adjustments', [PlayerController::class, 'adjustments'])->name('adjustments');
        Route::get('packages', [PackageController::class, 'index'])->name('packages');
        Route::get('anomalies', [AnomalyController::class, 'index'])->name('anomalies');
        Route::get('audit', AuditController::class)->name('audit');
        Route::get('minigame', [MiniGameController::class, 'index'])->name('minigame');
        Route::get('support', [AdminSupportController::class, 'index'])->name('support');
        Route::get('support/{ticket}', [AdminSupportController::class, 'show'])->name('support.show');
        Route::post('support/{ticket}/reply', [AdminSupportController::class, 'reply'])->name('support.reply');
        Route::post('support/{ticket}/close', [AdminSupportController::class, 'close'])->name('support.close');

        Route::middleware('admin.role:reviewer')->group(function () {
            Route::post('refunds/{refund}/review-approve', [AdminRefundController::class, 'reviewApprove'])->name('refunds.review-approve');
            Route::post('refunds/{refund}/review-reject', [AdminRefundController::class, 'reviewReject'])->name('refunds.review-reject');
            Route::post('players/{player}/adjustments', [PlayerController::class, 'initiateAdjustment'])->name('players.adjust');
        });

        Route::middleware('admin.role:supervisor')->group(function () {
            Route::post('refunds/{refund}/approve', [AdminRefundController::class, 'approve'])->middleware('throttle:sensitive')->name('refunds.approve');
            Route::post('refunds/{refund}/reject', [AdminRefundController::class, 'reject'])->name('refunds.reject');
            Route::post('refunds/{refund}/retry', [AdminRefundController::class, 'retry'])->name('refunds.retry');
            Route::post('refunds/{refund}/close', [AdminRefundController::class, 'close'])->middleware('throttle:sensitive')->name('refunds.close');

            Route::post('adjustments/{adjustment}/approve', [PlayerController::class, 'approveAdjustment'])->middleware('throttle:sensitive')->name('adjustments.approve');
            Route::post('adjustments/{adjustment}/reject', [PlayerController::class, 'rejectAdjustment'])->name('adjustments.reject');
            Route::post('players/{player}/suspend', [PlayerController::class, 'suspend'])->middleware('throttle:sensitive')->name('players.suspend');
            Route::post('players/{player}/unsuspend', [PlayerController::class, 'unsuspend'])->middleware('throttle:sensitive')->name('players.unsuspend');

            Route::get('accounts', [AccountController::class, 'index'])->name('accounts');
            Route::post('accounts/{account}/disable', [AccountController::class, 'disable'])->middleware('throttle:sensitive')->name('accounts.disable');
            Route::post('accounts/{account}/enable', [AccountController::class, 'enable'])->middleware('throttle:sensitive')->name('accounts.enable');

            Route::post('packages', [PackageController::class, 'store'])->middleware('throttle:sensitive')->name('packages.store');
            Route::post('packages/{package}', [PackageController::class, 'update'])->middleware('throttle:sensitive')->name('packages.update');

            Route::post('minigame/toggle', [MiniGameController::class, 'toggle'])->middleware('throttle:sensitive')->name('minigame.toggle');

            Route::post('anomalies/{anomaly}/resolve', [AnomalyController::class, 'resolve'])->name('anomalies.resolve');
            Route::post('anomalies/{anomaly}/retry', [AnomalyController::class, 'retry'])->middleware('throttle:sensitive')->name('anomalies.retry');

            // DEMO 測試面板：非 production 才註冊（production 本身也無法啟動）。
            if (! app()->environment('production')) {
                Route::get('demo', [DemoController::class, 'index'])->name('demo');
                Route::post('demo/reset', [DemoController::class, 'reset'])->name('demo.reset');
                Route::post('demo/expire-packet', [DemoController::class, 'expirePacket'])->name('demo.expire-packet');
                Route::post('demo/overdue-refund', [DemoController::class, 'overdueRefund'])->name('demo.overdue-refund');
                Route::post('demo/next-payout', [DemoController::class, 'nextPayout'])->name('demo.next-payout');
                Route::post('demo/next-game-topup', [DemoController::class, 'nextGameTopup'])->name('demo.next-game-topup');
            }
        });
    });
});
