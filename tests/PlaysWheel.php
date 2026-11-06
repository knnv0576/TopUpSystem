<?php

namespace Tests;

use App\DemoGame\Models\GamePlayer;
use App\MiniGame\Models\WheelRound;
use App\MiniGame\Services\WheelService;
use App\MiniGame\Support\WheelOutcome;
use App\Support\ApiSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait PlaysWheel
{
    protected function wheel(): WheelService
    {
        return app(WheelService::class);
    }

    protected function committed(GamePlayer $player): WheelRound
    {
        return $this->wheel()->ensureCommitted($player);
    }

    /** 找出能讓這一局轉到指定倍率的玩家種子。 */
    protected function seedFor(WheelRound $round, int $multiplierPct): string
    {
        for ($i = 0; $i < 100000; $i++) {
            $seed = "test-seed-$i";
            $roll = WheelOutcome::roll($round->server_seed, $seed, $round->public_id);
            if (WheelOutcome::segmentForRoll($roll)['multiplier_pct'] === $multiplierPct) {
                return $seed;
            }
        }

        $this->fail('找不到符合的種子');
    }

    /** 透過網頁端點轉一局，並指定結果的倍率。 */
    protected function spin(GamePlayer $player, int $multiplierPct = 100, int $bet = 100): TestResponse
    {
        $round = $this->committed($player);

        return $this->actingAs($player, 'game')->postJson(route('game.wheel.spin', $round->public_id), [
            'bet' => $bet,
            'client_seed' => $this->seedFor($round, $multiplierPct),
        ]);
    }

    /** 開局但不往下處理，局會停在 debit_pending。 */
    protected function begin(GamePlayer $player, int $multiplierPct = 100, int $bet = 100): WheelRound
    {
        $round = $this->committed($player);

        return $this->wheel()->begin($player, $round->public_id, $bet, $this->seedFor($round, $multiplierPct))[0];
    }

    protected function signedPost(string $path, array $data, ?string $secret = null): TestResponse
    {
        $body = json_encode($data);
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach (ApiSignature::headers($secret ?? config('topup.game_api_secret'), 'POST', $path, $body) as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    /** 繞過觸發器直接改資料，模擬資料庫層的竄改。 */
    protected function tamper(WheelRound $round, array $changes): void
    {
        DB::statement('ALTER TABLE minigame_rounds DISABLE TRIGGER minigame_rounds_guard');
        DB::table('minigame_rounds')->where('id', $round->id)->update($changes);
        DB::statement('ALTER TABLE minigame_rounds ENABLE TRIGGER minigame_rounds_guard');
    }

    protected function ledgerCount(GamePlayer $player, string $type): int
    {
        return DB::table('game_point_ledger')->where('player_id', $player->id)->where('type', $type)->count();
    }
}
