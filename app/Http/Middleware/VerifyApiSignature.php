<?php

namespace App\Http\Middleware;

use App\Support\ApiSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * 驗證伺服器間 API 的 HMAC 簽章，並用時間窗與 nonce 防止重放。
 * 參數是 config/topup.php 內的密鑰名稱。
 */
class VerifyApiSignature
{
    public function handle(Request $request, Closure $next, string $secretName): Response
    {
        $timestamp = (string) $request->header('X-Timestamp');
        $nonce = (string) $request->header('X-Nonce');
        $signature = (string) $request->header('X-Signature');

        $inWindow = ctype_digit($timestamp) && abs(now()->timestamp - (int) $timestamp) <= config('topup.signature_window_seconds');
        $validNonce = strlen($nonce) >= 16 && strlen($nonce) <= 64;

        if (! $inWindow || ! $validNonce) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $expected = ApiSignature::sign(
            (string) config("topup.$secretName"),
            $timestamp,
            $nonce,
            $request->method(),
            $request->getPathInfo(),
            $request->getContent(),
        );

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        // 只有通過驗簽的請求才佔用 nonce。
        if (DB::table('api_nonces')->insertOrIgnore(['nonce' => $nonce, 'created_at' => now()]) === 0) {
            return response()->json(['error' => 'replayed'], 401);
        }

        return $next($request);
    }
}
