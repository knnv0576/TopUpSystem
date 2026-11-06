<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * 伺服器間 API 簽章：HMAC-SHA256(時間戳\nnonce\n方法\n路徑\n內容)。
 */
class ApiSignature
{
    public static function headers(string $secret, string $method, string $path, string $body): array
    {
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();

        return [
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => self::sign($secret, $timestamp, $nonce, $method, $path, $body),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    public static function sign(string $secret, string $timestamp, string $nonce, string $method, string $path, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [$timestamp, $nonce, strtoupper($method), '/'.ltrim($path, '/'), $body]), $secret);
    }
}
