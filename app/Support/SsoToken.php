<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * 玩家端簽發、充值網站驗證的單次登入 token。是否已使用由充值端的 sso_tokens 判斷。
 */
class SsoToken
{
    public static function issue(int $gamePlayerId): string
    {
        $payload = self::encode(json_encode([
            'sub' => $gamePlayerId,
            'jti' => (string) Str::uuid(),
            'exp' => now()->timestamp + config('topup.sso_ttl_seconds'),
        ]));

        return $payload.'.'.hash_hmac('sha256', $payload, config('topup.game_api_secret'));
    }

    /** @return array{sub:int,jti:string,exp:int}|null 簽章錯誤或過期時回傳 null */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $signature] = $parts;
        if (! hash_equals(hash_hmac('sha256', $payload, config('topup.game_api_secret')), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (! is_array($data) || ! isset($data['sub'], $data['jti'], $data['exp']) || $data['exp'] < now()->timestamp) {
            return null;
        }

        return $data;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
