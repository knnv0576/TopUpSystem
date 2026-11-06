<?php

use App\Http\Middleware\AdminIpAllowlist;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureGamePlayer;
use App\Http\Middleware\EnsureShopPlayer;
use App\Http\Middleware\VerifyApiSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.signature' => VerifyApiSignature::class,
            'admin.ip' => AdminIpAllowlist::class,
            'admin' => EnsureAdmin::class,
            'admin.role' => EnsureAdminRole::class,
            'game.player' => EnsureGamePlayer::class,
            'shop.player' => EnsureShopPlayer::class,
        ]);
        // 單次 token 本身已簽章且只能用一次；這裡是唯一免 CSRF 的網頁端點。
        $middleware->validateCsrfTokens(except: ['shop/sso']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'bank_account', 'code']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'webhooks/*') || $request->expectsJson(),
        );
    })->create();
