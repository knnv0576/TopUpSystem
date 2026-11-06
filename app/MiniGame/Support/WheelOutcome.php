<?php

namespace App\MiniGame\Support;

use RuntimeException;

/** 轉盤結果的純計算。規則要和 public/wheel.js 的驗證程式一致。 */
class WheelOutcome
{
    /** floor(2^32 / 100) * 100：小於它才採用，取餘數才不會有偏差。 */
    public const ACCEPT_BELOW = 4294967200;

    private const MAX_ATTEMPTS = 1000;

    public static function roll(string $serverSeed, string $clientSeed, string $publicId): int
    {
        return self::select(fn (int $attempt) => self::draw($serverSeed, $clientSeed, $publicId, $attempt));
    }

    public static function draw(string $serverSeed, string $clientSeed, string $publicId, int $attempt): int
    {
        $digest = hash_hmac('sha256', "$clientSeed:$publicId:$attempt", $serverSeed, true);

        return unpack('N', substr($digest, 0, 4))[1];
    }

    /** @param callable(int): int $draw 依嘗試次數回傳 uint32 */
    public static function select(callable $draw): int
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $value = $draw($attempt);
            if ($value < self::ACCEPT_BELOW) {
                return $value % 100;
            }
        }

        throw new RuntimeException('無法產生轉盤結果');
    }

    public static function segments(): array
    {
        return config('minigame.wheel.segments');
    }

    public static function segmentForRoll(int $roll): array
    {
        $upTo = 0;
        foreach (self::segments() as $segment) {
            $upTo += $segment['weight'];
            if ($roll < $upTo) {
                return $segment;
            }
        }

        throw new RuntimeException('roll 超出賠率表範圍');
    }

    public static function payout(int $bet, int $multiplierPct): int
    {
        return intdiv($bet * $multiplierPct, 100);
    }

    public static function maxMultiplierPct(): int
    {
        return max(array_column(self::segments(), 'multiplier_pct'));
    }

    /** 給玩家看的結果：低於下注額的結果不算中獎。 */
    public static function summary(int $bet, int $payout): array
    {
        $net = $payout - $bet;
        $signed = ($net > 0 ? '+' : '').number_format($net);

        return match (true) {
            $net > 0 => ['outcome' => 'win', 'net' => $net, 'text' => '贏了！拿回 '.number_format($payout)." 點，本局 $signed"],
            $net === 0 => ['outcome' => 'even', 'net' => 0, 'text' => '打平，拿回 '.number_format($payout).' 點'],
            $payout > 0 => ['outcome' => 'loss', 'net' => $net, 'text' => '拿回 '.number_format($payout)." 點，本局 $signed"],
            default => ['outcome' => 'loss', 'net' => $net, 'text' => "本局 $signed"],
        };
    }

    /** 六個連續扇形的 SVG 路徑；從正上方順時針排列，大小等於機率。 */
    public static function sectors(float $radius = 100.0): array
    {
        $point = function (float $degrees) use ($radius) {
            $radians = deg2rad($degrees - 90);

            return sprintf('%.3f %.3f', $radius * cos($radians), $radius * sin($radians));
        };

        $sectors = [];
        $from = 0.0;
        foreach (self::segments() as $segment) {
            $to = $from + $segment['weight'] * 3.6;
            $large = $to - $from > 180 ? 1 : 0;
            $middleDegrees = ($from + $to) / 2 - 90;
            $middle = deg2rad($middleDegrees);
            $sectors[] = $segment + [
                'path' => "M 0 0 L {$point($from)} A $radius $radius 0 $large 1 {$point($to)} Z",
                'label_x' => round($radius * 0.7 * cos($middle), 2),
                'label_y' => round($radius * 0.7 * sin($middle), 2),
                // 文字沿半徑方向排列，窄的扇形才放得下。
                'label_rotate' => round($middleDegrees, 2),
            ];
            $from = $to;
        }

        return $sectors;
    }
}
