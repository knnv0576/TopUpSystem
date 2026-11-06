<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * admin guard 只有在通過密碼與 TOTP 後才會登入。
 * 每次請求都重新檢查帳號狀態：停用或鎖定會讓既有 session 立即失效。
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('admin');
        $admin = $guard->user();

        if (! $admin) {
            return redirect()->route('admin.login');
        }

        if (! $admin->canAuthenticate()) {
            $guard->logout();
            $request->session()->regenerate();

            return redirect()->route('admin.login')->withErrors(['username' => '帳號暫時無法使用']);
        }

        $session = $request->session();
        $key = $admin->fingerprintSessionKey();
        if (! $session->has($key)) {
            $session->put($key, $admin->sessionFingerprint());
        } elseif (! hash_equals($session->get($key), $admin->sessionFingerprint())) {
            AuditLog::record('admin.session_invalidated', $admin, ['note' => '密碼或 TOTP 已變更'], $admin);
            $guard->logout();
            $session->forget($key);
            $session->regenerate();

            return redirect()->route('admin.login')->withErrors(['username' => '帳號密碼已變更，請重新登入']);
        }

        Auth::shouldUse('admin');

        // 使用初始密碼時，只能改密碼或登出。
        if ($admin->must_change_password && ! $request->routeIs('admin.password', 'admin.password.update', 'admin.logout')) {
            return redirect()->route('admin.password');
        }

        return $next($request);
    }
}
