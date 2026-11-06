<?php

namespace App\MiniGame\Services;

use App\DemoGame\Models\GamePlayer;
use App\MiniGame\Exceptions\SpinRefused;
use App\MiniGame\Models\MiniGameSetting;
use App\MiniGame\Models\WheelRound;
use App\MiniGame\Support\RoundIntegrity;
use App\MiniGame\Support\WheelOutcome;
use App\Models\AuditLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 一局的狀態機：committed → debit_pending → payout_pending → settled。
 * 結果在扣點前就固定，玩家斷線或重送都改變不了；任何一步都能安全重試。
 */
class WheelService
{
    public function __construct(private PointsClient $points) {}

    /** 確保玩家有一筆待用的局，並回傳它。開局前只公布種子的雜湊。 */
    public function ensureCommitted(GamePlayer $player): WheelRound
    {
        $seed = bin2hex(random_bytes(32));

        // 部分唯一索引讓每位玩家只有一筆，併發時由資料庫決定誰成功。
        WheelRound::query()->insertOrIgnore([
            'public_id' => (string) Str::uuid(),
            'game_player_id' => $player->id,
            'player_no' => $player->player_no,
            'status' => 'committed',
            'server_seed' => encrypt($seed, false),
            'server_seed_hash' => hash('sha256', $seed),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WheelRound::where('game_player_id', $player->id)->where('status', 'committed')->firstOrFail();
    }

    /** 網頁端的轉動：只有開局成功的請求會往下處理，重送只回傳現況。 */
    public function spin(GamePlayer $player, string $publicId, int $bet, string $clientSeed): WheelRound
    {
        [$round, $started] = $this->begin($player, $publicId, $bet, $clientSeed);

        return $started ? $this->advance($round) : $round;
    }

    /**
     * 開局：只接受第一次送出的內容。
     *
     * @return array{0: WheelRound, 1: bool} 局，以及這次請求是否成功開局
     */
    public function begin(GamePlayer $player, string $publicId, int $bet, string $clientSeed): array
    {
        $round = WheelRound::where('public_id', $publicId)->where('game_player_id', $player->id)->firstOrFail();

        if ($round->status !== 'committed') {
            return [$this->assertSameRequest($round, $bet, $clientSeed), false];
        }
        if (! MiniGameSetting::wheelEnabled()) {
            throw new SpinRefused('轉盤目前暫停服務', 409);
        }
        if (! in_array($bet, config('minigame.wheel.bets'), true)) {
            throw new SpinRefused('下注檔位不正確', 422);
        }
        if (! RoundIntegrity::seedMatches($round)) {
            return [$this->hold($round, '種子與開局前公布的雜湊不符'), false];
        }

        $roll = WheelOutcome::roll($round->server_seed, $clientSeed, $round->public_id);
        $multiplierPct = WheelOutcome::segmentForRoll($roll)['multiplier_pct'];
        $outcome = [
            'client_seed' => $clientSeed,
            'bet_points' => $bet,
            'roll' => $roll,
            'multiplier_pct' => $multiplierPct,
            'payout_points' => WheelOutcome::payout($bet, $multiplierPct),
        ];
        $mac = RoundIntegrity::mac($outcome + $round->only(['public_id', 'game_player_id', 'player_no', 'server_seed_hash']));

        $updated = WheelRound::whereKey($round->id)->where('status', 'committed')->update($outcome + [
            'status' => 'debit_pending',
            'integrity_mac' => $mac,
            'spun_at' => now(),
        ]);

        return $updated === 1
            ? [$round->fresh(), true]
            : [$this->assertSameRequest($round->fresh(), $bet, $clientSeed), false];
    }

    /** 把局往前推到不能再自動前進為止。可以重複呼叫；點數異動由冪等鍵保證只發生一次。 */
    public function advance(WheelRound $round): WheelRound
    {
        $round = $round->fresh();

        if (in_array($round->status, WheelRound::IN_PROGRESS, true) && ! RoundIntegrity::isValid($round)) {
            return $this->hold($round, '檢查碼不符');
        }

        if ($round->status === 'debit_pending') {
            $round = $this->debit($round);
        }
        if ($round->status === 'payout_pending') {
            $round = $this->payout($round);
        }

        return $round;
    }

    /** 排程補做中斷的局。只碰一段時間沒有更新的局，避開正在處理的請求。 */
    public function reconcile(): int
    {
        $count = 0;
        WheelRound::whereIn('status', WheelRound::IN_PROGRESS)
            ->where('updated_at', '<', now()->subSeconds((int) config('minigame.retry_after_seconds')))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (WheelRound $round) use (&$count) {
                try {
                    $this->advance($round);
                    $count++;
                } catch (Throwable $e) {
                    Log::warning('minigame.reconcile_failed', ['round' => $round->id, 'error' => $e->getMessage()]);
                }
            });

        return $count;
    }

    private function debit(WheelRound $round): WheelRound
    {
        // 超過期限仍沒扣點成功：不再送扣點，改為作廢。
        if ($round->spun_at->lt(now()->subSeconds((int) config('minigame.debit_void_after_seconds')))) {
            return $this->voidOrContinue($round, 'timeout');
        }

        $response = $this->call(fn () => $this->points->debit($round));
        if (! $response) {
            return $round;
        }

        if ($response->successful()) {
            return $this->transition($round, 'debit_pending', 'payout_pending');
        }

        return match ([$response->status(), $response->json('error')]) {
            [422, 'insufficient_points'] => $this->voidOrContinue($round, 'insufficient_points'),
            [403, 'player_suspended'] => $this->voidOrContinue($round, 'player_suspended'),
            [409, 'debit_voided'] => $this->voidOrContinue($round, 'timeout'),
            [409, 'idempotency_conflict'] => $this->hold($round, '帳本的下注額與局資料不符'),
            default => $round, // 不確定的回應維持原狀，由排程重試。
        };
    }

    /**
     * 標成未成立之前先作廢扣點，之前送出、還沒生效的扣點就不會再扣到點數。
     * 扣點其實已經生效時不作廢，局繼續往發獎走。作廢也連不上時維持原狀，之後再試。
     */
    private function voidOrContinue(WheelRound $round, string $reason): WheelRound
    {
        $response = $this->call(fn () => $this->points->voidDebit($round));
        if (! $response) {
            return $round;
        }

        return match ([$response->status(), $response->json('status') ?? $response->json('error')]) {
            [200, 'voided'] => $this->transition($round, 'debit_pending', 'rejected', ['reject_reason' => $reason]),
            [200, 'debited'] => $this->transition($round, 'debit_pending', 'payout_pending'),
            [409, 'idempotency_conflict'] => $this->hold($round, '帳本的下注額與局資料不符'),
            default => $round,
        };
    }

    private function payout(WheelRound $round): WheelRound
    {
        if ($round->payout_points === 0) {
            return $this->settle($round);
        }

        $response = $this->call(fn () => $this->points->credit($round));
        if (! $response) {
            return $round;
        }

        if ($response->successful()) {
            return $this->settle($round);
        }

        return $response->status() === 409
            ? $this->hold($round, '發獎被點數 API 拒絕：'.$response->json('error'))
            : $round;
    }

    private function settle(WheelRound $round): WheelRound
    {
        return $this->transition($round, 'payout_pending', 'settled', ['settled_at' => now()]);
    }

    /** 回應遺失或服務中斷時回傳 null，局維持原狀。 */
    private function call(callable $request): ?Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            Log::warning('minigame.points_api_unreachable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function transition(WheelRound $round, string $from, string $to, array $extra = []): WheelRound
    {
        WheelRound::whereKey($round->id)->where('status', $from)->update(['status' => $to] + $extra);

        return $round->fresh();
    }

    /** 停止自動處理並留下稽核紀錄。局會永遠停在這個狀態作為證據。 */
    private function hold(WheelRound $round, string $reason): WheelRound
    {
        DB::transaction(function () use ($round, $reason) {
            $updated = WheelRound::whereKey($round->id)
                ->whereIn('status', ['committed', ...WheelRound::IN_PROGRESS])
                ->update(['status' => 'integrity_hold']);

            if ($updated === 1) {
                AuditLog::record('minigame.integrity_hold', $round, [
                    'from' => $round->status,
                    'to' => 'integrity_hold',
                    'points' => $round->bet_points,
                    'note' => "局 {$round->public_id}：$reason",
                ]);
            }
        });

        return $round->fresh();
    }

    private function assertSameRequest(WheelRound $round, int $bet, string $clientSeed): WheelRound
    {
        if ($round->status === 'integrity_hold' && $round->bet_points === null) {
            return $round;
        }
        if ($round->bet_points !== $bet || $round->client_seed !== $clientSeed) {
            throw new SpinRefused('這一局已經開始，內容不能修改', 409);
        }

        return $round;
    }
}
