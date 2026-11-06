<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/** 只看連線來源 IP（未設定 trusted proxies，Laravel 不會採用 X-Forwarded-For）。 */
class AdminIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->server('REMOTE_ADDR');

        if (! IpUtils::checkIp($ip, config('topup.admin_ip_allowlist'))) {
            abort(403, '這個來源不能使用管理後台');
        }

        return $next($request);
    }
}
