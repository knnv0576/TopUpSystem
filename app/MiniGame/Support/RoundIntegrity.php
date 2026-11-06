<?php

namespace App\MiniGame\Support;

use App\MiniGame\Models\WheelRound;
use Throwable;

/**
 * 局資料的完整性檢查碼。密鑰由 APP_KEY 導出、不存放在資料庫，
 * 只改得到資料庫的人無法偽造。更換 APP_KEY 會讓舊局的檢查碼失效。
 */
class RoundIntegrity
{
    public static function mac(array $round): string
    {
        return hash_hmac('sha256', json_encode([
            (string) $round['public_id'],
            (int) $round['game_player_id'],
            (string) $round['player_no'],
            (string) $round['server_seed_hash'],
            (string) $round['client_seed'],
            (int) $round['bet_points'],
            (int) $round['roll'],
            (int) $round['multiplier_pct'],
            (int) $round['payout_points'],
        ]), self::key());
    }

    public static function seedMatches(WheelRound $round): bool
    {
        try {
            return hash_equals($round->server_seed_hash, hash('sha256', $round->server_seed));
        } catch (Throwable) {
            return false; // 密文被改過就解不開。
        }
    }

    /** 已開局的局：檢查碼相符，種子也和開局前公布的雜湊相符。 */
    public static function isValid(WheelRound $round): bool
    {
        if ($round->integrity_mac === null || ! self::seedMatches($round)) {
            return false;
        }

        return hash_equals($round->integrity_mac, self::mac($round->only([
            'public_id', 'game_player_id', 'player_no', 'server_seed_hash', 'client_seed', 'bet_points', 'roll', 'multiplier_pct', 'payout_points',
        ])));
    }

    private static function key(): string
    {
        return hash_hkdf('sha256', (string) config('app.key'), 32, 'minigame-round-integrity');
    }
}
