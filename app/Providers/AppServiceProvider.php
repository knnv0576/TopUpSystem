<?php

namespace App\Providers;

use App\Game\GameGateway;
use App\Game\HttpGameGateway;
use App\Payments\MockGateway;
use App\Payments\PaymentGateway;
use App\Support\DemoGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        DemoGuard::assertNotProduction((string) $this->app->environment());

        $this->app->singleton(PaymentGateway::class, MockGateway::class);
        $this->app->singleton(GameGateway::class, HttpGameGateway::class);
    }

    public function boot(): void
    {
        DemoGuard::assertLocalMail((string) config('mail.default'), config('mail.mailers.smtp.host'));

        // 依「端點 + 來源 IP」分開計數。內建的 throttle:次數,分鐘 會讓相同數字的端點共用額度，
        // 兩位管理員連續完成首次登入就會互相擋住。針對單一帳號的猜測由帳號鎖定處理。
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)
            ->by($request->route()?->uri().'|'.$request->server('REMOTE_ADDR')));

        // 已登入的高風險操作：依「端點 + 管理員」計數。
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(10)
            ->by($request->route()?->uri().'|'.Auth::guard('admin')->id()));
    }
}
