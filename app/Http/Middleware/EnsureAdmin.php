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
    /** 通過 TOTP、完成登入的時間；超過絕對期限就要重新登入。 */
    public const AUTHENTICATED_AT = 'admin_authenticated_at';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('admin');
        $admin = $guard->user();

        if (! $admin) {
            return redirect()->route('admin.login');
        }

        // 閒置逾時由後台的 session 設定處理（UseAdminSession）；這裡處理不論有沒有操作的絕對期限。
        // 沒有登入時間（不是經由 TOTP 登入的 session）一律視為過期，不能重新起算。
        $session = $request->session();
        $authenticatedAt = (int) $session->get(self::AUTHENTICATED_AT, 0);
        $hours = config('topup.admin_absolute_hours');
        if (now()->getTimestamp() - $authenticatedAt > $hours * 3600) {
            AuditLog::record('admin.session_expired', $admin, ['note' => $authenticatedAt === 0 ? '沒有登入時間' : "登入超過 {$hours} 小時"], $admin);
            $guard->logout();
            // 整個後台 session 作廢，不把舊資料（例如原本要去的頁面）帶給下一位登入的人。
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->route('admin.login')->withErrors(['username' => "登入已超過 {$hours} 小時，請重新登入"]);
        }

        if (! $admin->canAuthenticate()) {
            $guard->logout();
            $request->session()->regenerate();

            return redirect()->route('admin.login')->withErrors(['username' => '帳號暫時無法使用']);
        }

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
