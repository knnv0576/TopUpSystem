<?php

namespace Tests\Feature;

use App\MiniGame\Models\MiniGameSetting;
use App\MiniGame\Models\WheelRound;
use App\MiniGame\Support\RoundIntegrity;
use App\MiniGame\Support\WheelOutcome;
use App\Models\AuditLog;
use App\Models\Player;
use App\Services\DemoTools;
use App\Services\PlayerSuspensionService;
use App\Support\PlayerMessages;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PlaysWheel;
use Tests\TestCase;

class WheelTest extends TestCase
{
    use PlaysWheel;

    private function suspend($player): void
    {
        app(PlayerSuspensionService::class)->suspend(Player::forGame($player->id), $this->admin('supervisor'), '測試');
    }

    private function debit(array $data)
    {
        return $this->signedPost('/api/points/debit', $data);
    }

    private function credit(array $data)
    {
        return $this->signedPost('/api/points/credit', $data);
    }

    // 扣點 API

    public function test_debit_replay_succeeds_after_suspension_without_charging_twice(): void
    {
        $alice = $this->player('alice', 1000);
        $form = ['player_no' => $alice->player_no, 'points' => 100, 'idempotency_key' => 'k1', 'purpose' => '測試'];

        $this->debit($form)->assertOk();
        $this->suspend($alice);

        $this->debit($form)->assertOk();
        $this->assertSame(900, $this->wallet($alice)[0]);

        $this->debit(['idempotency_key' => 'k2'] + $form)->assertForbidden()->assertJson(['error' => 'player_suspended']);
        $this->assertSame(900, $this->wallet($alice)[0]);
        $this->assertLedgerConsistent();
    }

    public function test_debit_rejects_same_key_with_different_points(): void
    {
        $alice = $this->player('alice', 1000);
        $form = ['player_no' => $alice->player_no, 'points' => 100, 'idempotency_key' => 'k1', 'purpose' => '測試'];

        $this->debit($form)->assertOk();
        $this->debit(['points' => 200] + $form)->assertStatus(409)->assertJson(['error' => 'idempotency_conflict']);

        $this->assertSame(900, $this->wallet($alice)[0]);
    }

    // 加點 API

    public function test_credit_requires_a_matching_debit_and_pays_once(): void
    {
        $alice = $this->player('alice', 1000);
        $bet = ['player_no' => $alice->player_no, 'points' => 100, 'idempotency_key' => 'k1', 'purpose' => '下注'];
        $win = ['player_no' => $alice->player_no, 'points' => 500, 'debit_idempotency_key' => 'k1', 'purpose' => '獎勵'];

        $this->credit($win)->assertStatus(409)->assertJson(['error' => 'debit_not_found']);
        $this->assertSame(1000, $this->wallet($alice)[0]);

        $this->debit($bet)->assertOk();
        $this->credit($win)->assertOk();
        $this->credit($win)->assertOk();
        $this->assertSame(1400, $this->wallet($alice)[0]);
        $this->assertSame(1, $this->ledgerCount($alice, 'game_credit'));

        $this->credit(['points' => 400] + $win)->assertStatus(409)->assertJson(['error' => 'idempotency_conflict']);
        $this->assertSame(1400, $this->wallet($alice)[0]);
        $this->assertLedgerConsistent();
    }

    public function test_credit_cannot_exceed_bet_times_top_multiplier(): void
    {
        $alice = $this->player('alice', 1000);
        $this->debit(['player_no' => $alice->player_no, 'points' => 100, 'idempotency_key' => 'k1', 'purpose' => '下注'])->assertOk();

        $win = ['player_no' => $alice->player_no, 'debit_idempotency_key' => 'k1', 'purpose' => '獎勵'];
        $this->credit(['points' => 1001] + $win)->assertStatus(409)->assertJson(['error' => 'payout_exceeds_limit']);
        $this->assertSame(900, $this->wallet($alice)[0]);

        $this->credit(['points' => 1000] + $win)->assertOk();
        $this->assertSame(1900, $this->wallet($alice)[0]);
    }

    public function test_credit_rejects_bad_signature_and_keys_are_per_player(): void
    {
        $alice = $this->player('alice', 1000);
        $bob = $this->player('bob', 1000);
        $this->debit(['player_no' => $alice->player_no, 'points' => 100, 'idempotency_key' => 'k1', 'purpose' => '下注'])->assertOk();

        $this->signedPost('/api/points/credit', ['player_no' => $alice->player_no, 'points' => 100, 'debit_idempotency_key' => 'k1', 'purpose' => '獎勵'], 'wrong')->assertStatus(401);
        $this->credit(['player_no' => $bob->player_no, 'points' => 100, 'debit_idempotency_key' => 'k1', 'purpose' => '獎勵'])->assertStatus(409);

        $this->assertSame(900, $this->wallet($alice)[0]);
        $this->assertSame(1000, $this->wallet($bob)[0]);
    }

    public function test_suspended_player_can_still_receive_payout_for_placed_bet(): void
    {
        $alice = $this->player('alice', 1000);
        $this->debit(['player_no' => $alice->player_no, 'points' => 100, 'idempotency_key' => 'k1', 'purpose' => '下注'])->assertOk();
        $this->suspend($alice);

        $this->credit(['player_no' => $alice->player_no, 'points' => 200, 'debit_idempotency_key' => 'k1', 'purpose' => '獎勵'])->assertOk();
        $this->assertSame(1100, $this->wallet($alice)[0]);
    }

    // 賠率與結果

    public function test_paytable_is_exactly_95_percent_and_payouts_are_whole_points(): void
    {
        $segments = config('minigame.wheel.segments');

        $this->assertSame(100, array_sum(array_column($segments, 'weight')));
        $this->assertSame(9500, array_sum(array_map(fn ($s) => $s['weight'] * $s['multiplier_pct'], $segments)));
        $this->assertSame(95, config('minigame.wheel.target_rtp_pct'));

        foreach (config('minigame.wheel.bets') as $bet) {
            foreach ($segments as $segment) {
                $this->assertSame(0, ($bet * $segment['multiplier_pct']) % 100, "檔位 $bet 倍率 {$segment['multiplier_pct']}");
            }
        }

        // 每個 roll 都要落在賠率表內，而且份數等於機率。
        $hits = array_fill_keys(array_column($segments, 'key'), 0);
        for ($roll = 0; $roll < 100; $roll++) {
            $hits[WheelOutcome::segmentForRoll($roll)['key']]++;
        }
        $this->assertSame(array_column($segments, 'weight', 'key'), $hits);
        $this->assertSame(10000, WheelOutcome::payout(1000, WheelOutcome::maxMultiplierPct()));
    }

    /** 期望值由 Node 的 crypto 獨立算出，確保不是拿同一份程式驗自己。 */
    public function test_roll_matches_independently_computed_vector(): void
    {
        $seed = str_repeat('a', 64);

        $this->assertSame('ffe054fe7ae0cb6dc65c3af9b61d5209f439851db43d0ba5997337df154668eb', hash('sha256', $seed));
        $this->assertSame(1084004864, WheelOutcome::draw($seed, 'client-seed-1', '11111111-2222-4333-8444-555555555555', 0));
        $this->assertSame(64, WheelOutcome::roll($seed, 'client-seed-1', '11111111-2222-4333-8444-555555555555'));
    }

    public function test_rejection_sampling_discards_biased_values(): void
    {
        $calls = [];
        $roll = WheelOutcome::select(function (int $attempt) use (&$calls) {
            $calls[] = $attempt;

            return $attempt === 0 ? WheelOutcome::ACCEPT_BELOW : 4294967199;
        });

        $this->assertSame([0, 1], $calls);
        $this->assertSame(99, $roll);
    }

    public function test_summary_never_presents_a_loss_as_a_win(): void
    {
        $this->assertSame('本局 -100', WheelOutcome::summary(100, 0)['text']);
        $this->assertSame('拿回 50 點，本局 -50', WheelOutcome::summary(100, 50)['text']);
        $this->assertSame('loss', WheelOutcome::summary(100, 50)['outcome']);
        $this->assertSame('even', WheelOutcome::summary(100, 100)['outcome']);
        $this->assertSame('win', WheelOutcome::summary(100, 200)['outcome']);
    }

    // 轉盤流程

    public function test_winning_spin_debits_then_credits_and_reveals_seed(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->committed($alice);
        $seed = $round->server_seed;

        $this->actingAs($alice, 'game')->get(route('game.wheel'))->assertOk()
            ->assertSee($round->server_seed_hash)->assertDontSee($seed);

        $response = $this->spin($alice, 500)->assertOk()->assertJson([
            'state' => 'settled', 'outcome' => 'win', 'payout' => 500, 'net' => 400, 'available' => 1400, 'server_seed' => $seed,
        ]);

        $this->assertSame(hash('sha256', $response->json('server_seed')), $response->json('server_seed_hash'));
        $this->assertNotSame($round->public_id, $response->json('next.public_id'));
        $this->assertSame([1400, 0], $this->wallet($alice));
        $this->assertSame(1, $this->ledgerCount($alice, 'game_debit'));
        $this->assertSame(1, $this->ledgerCount($alice, 'game_credit'));
        $this->assertLedgerConsistent();
    }

    public function test_zero_multiplier_settles_without_credit(): void
    {
        $alice = $this->player('alice', 1000);

        $this->spin($alice, 0)->assertOk()->assertJson(['state' => 'settled', 'outcome' => 'loss', 'payout' => 0, 'message' => '本局 -100']);

        $this->assertSame([900, 0], $this->wallet($alice));
        $this->assertSame(0, $this->ledgerCount($alice, 'game_credit'));
    }

    public function test_tampered_bet_is_refused_without_charging(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->committed($alice);

        foreach ([123, 0, -100, 1000000] as $bet) {
            $this->actingAs($alice, 'game')->postJson(route('game.wheel.spin', $round->public_id), ['bet' => $bet, 'client_seed' => 'abcdefgh'])
                ->assertStatus(422);
        }

        $this->assertSame('committed', $round->fresh()->status);
        $this->assertSame(1000, $this->wallet($alice)[0]);
    }

    public function test_client_cannot_supply_outcome_fields(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->committed($alice);

        $this->actingAs($alice, 'game')->postJson(route('game.wheel.spin', $round->public_id), [
            'bet' => 100,
            'client_seed' => $this->seedFor($round, 0),
            'payout_points' => 999999,
            'multiplier_pct' => 1000,
            'roll' => 99,
            'status' => 'settled',
        ])->assertOk()->assertJson(['payout' => 0]);

        $this->assertSame(0, $round->fresh()->multiplier_pct);
        $this->assertSame(900, $this->wallet($alice)[0]);
    }

    public function test_only_first_submission_counts_and_replay_charges_once(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->committed($alice);
        $form = ['bet' => 100, 'client_seed' => $this->seedFor($round, 0)];
        $url = route('game.wheel.spin', $round->public_id);

        $this->actingAs($alice, 'game')->postJson($url, $form)->assertOk();
        $this->actingAs($alice, 'game')->postJson($url, $form)->assertOk()->assertJson(['state' => 'settled']);
        $this->assertSame(900, $this->wallet($alice)[0]);

        // 斷線後改內容再送：不覆蓋、不重新開局。
        $this->actingAs($alice, 'game')->postJson($url, ['bet' => 500] + $form)->assertStatus(409);
        $this->actingAs($alice, 'game')->postJson($url, ['client_seed' => $this->seedFor($round, 1000)] + $form)->assertStatus(409);

        $fresh = $round->fresh();
        $this->assertSame(100, $fresh->bet_points);
        $this->assertSame(0, $fresh->payout_points);
        $this->assertSame(900, $this->wallet($alice)[0]);
        $this->assertSame(1, $this->ledgerCount($alice, 'game_debit'));
    }

    public function test_insufficient_points_rejects_round_without_charging(): void
    {
        $alice = $this->player('alice', 50);

        $this->spin($alice, 100)->assertOk()->assertJson(['state' => 'rejected', 'available' => 50]);

        $this->assertSame([50, 0], $this->wallet($alice));
        $this->assertSame(0, $this->ledgerCount($alice, 'game_debit'));
        $this->assertSame('insufficient_points', WheelRound::where('status', 'rejected')->sole()->reject_reason);
    }

    public function test_frozen_points_cannot_be_bet(): void
    {
        $alice = $this->player('alice', 10050);
        $this->submitRefund($alice, 10000);
        $this->assertSame([10050, 10000], $this->wallet($alice));

        $this->spin($alice, 100, 100)->assertOk()->assertJson(['state' => 'rejected']);

        $this->assertSame([10050, 10000], $this->wallet($alice));
        $this->assertLedgerConsistent();
    }

    public function test_players_cannot_touch_each_others_rounds(): void
    {
        $alice = $this->player('alice', 1000);
        $bob = $this->player('bob', 1000);
        $round = $this->committed($alice);

        $this->actingAs($bob, 'game')->postJson(route('game.wheel.spin', $round->public_id), ['bet' => 100, 'client_seed' => 'abcdefgh'])->assertNotFound();
        $this->actingAs($bob, 'game')->getJson(route('game.wheel.show', $round->public_id))->assertNotFound();

        $this->assertSame('committed', $round->fresh()->status);
        $this->assertSame(1000, $this->wallet($alice)[0]);
        $this->assertSame(1000, $this->wallet($bob)[0]);
    }

    public function test_spins_are_rate_limited_to_30_per_minute(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->committed($alice);
        $form = ['bet' => 100, 'client_seed' => 'abcdefgh'];
        $url = route('game.wheel.spin', $round->public_id);

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($alice, 'game')->postJson($url, $form)->assertOk();
        }
        $this->actingAs($alice, 'game')->postJson($url, $form)->assertStatus(429);

        // 查詢狀態有自己的額度，輪詢不會影響轉盤，反之亦然。
        $this->actingAs($alice, 'game')->getJson(route('game.wheel.show', $round->public_id))->assertOk();
    }

    // 中斷與重試

    public function test_debit_outage_hides_result_and_retry_completes(): void
    {
        $alice = $this->player('alice', 1000);
        $this->failingPaths = ['/api/points/debit'];

        $response = $this->spin($alice, 500)->assertOk()->assertJson(['state' => 'processing']);
        foreach (['roll', 'payout', 'multiplier_pct', 'server_seed', 'outcome'] as $hidden) {
            $response->assertJsonMissingPath($hidden);
        }
        $this->assertSame(1000, $this->wallet($alice)[0]);

        // 還沒到重試時間，避開可能仍在進行中的請求。
        $this->failingPaths = [];
        $this->wheel()->reconcile();
        $this->assertSame('debit_pending', WheelRound::where('status', 'debit_pending')->sole()->status);

        $this->travel(2)->minutes();
        $this->wheel()->reconcile();

        $this->assertSame([1400, 0], $this->wallet($alice));
        $this->assertSame(1, WheelRound::where('status', 'settled')->count());
        $this->assertLedgerConsistent();
    }

    public function test_credit_outage_shows_result_without_seed_and_retry_pays_once(): void
    {
        $alice = $this->player('alice', 1000);
        $this->failingPaths = ['/api/points/credit'];

        $this->spin($alice, 500)->assertOk()->assertJson(['state' => 'crediting', 'payout' => 500])->assertJsonMissingPath('server_seed');
        $this->assertSame(900, $this->wallet($alice)[0]);

        $this->travel(2)->minutes();
        $this->wheel()->reconcile();
        $this->assertSame(900, $this->wallet($alice)[0], '服務還沒恢復，局維持原狀');
        $this->assertSame(1, WheelRound::where('status', 'payout_pending')->count());

        $this->failingPaths = [];
        $this->travel(2)->minutes();
        $this->wheel()->reconcile();
        $this->wheel()->reconcile();

        $this->assertSame(1400, $this->wallet($alice)[0]);
        $this->assertSame(1, $this->ledgerCount($alice, 'game_debit'));
        $this->assertSame(1, $this->ledgerCount($alice, 'game_credit'));
        $this->assertLedgerConsistent();
    }

    public function test_lost_debit_response_then_suspension_still_settles(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->begin($alice, 500);

        // 扣點已成功，但回應遺失；局還停在 debit_pending，接著玩家被停權。
        $this->debit(['player_no' => $alice->player_no, 'points' => 100, 'idempotency_key' => $round->debitKey(), 'purpose' => '轉盤下注'])->assertOk();
        $this->suspend($alice);

        $settled = $this->wheel()->advance($round);

        $this->assertSame('settled', $settled->status);
        $this->assertSame(1400, $this->wallet($alice)[0]);
        $this->assertLedgerConsistent();
    }

    public function test_round_not_yet_debited_is_rejected_when_player_is_suspended(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->begin($alice, 500);
        $this->suspend($alice);

        $rejected = $this->wheel()->advance($round);

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('player_suspended', $rejected->reject_reason);
        $this->assertSame(1000, $this->wallet($alice)[0]);
    }

    // 作廢扣點

    private function voidDebit(array $data)
    {
        return $this->signedPost('/api/points/debit/void', $data);
    }

    public function test_void_api_blocks_later_debit_and_reports_existing_debit(): void
    {
        $alice = $this->player('alice', 1000);
        $form = ['player_no' => $alice->player_no, 'points' => 100, 'idempotency_key' => 'k1'];

        $this->voidDebit($form)->assertOk()->assertJson(['status' => 'voided']);
        $this->voidDebit($form)->assertOk()->assertJson(['status' => 'voided']);
        $this->debit($form + ['purpose' => '測試'])->assertStatus(409)->assertJson(['error' => 'debit_voided']);
        $this->assertSame(1000, $this->wallet($alice)[0]);

        $this->debit(['idempotency_key' => 'k2', 'purpose' => '測試'] + $form)->assertOk();
        $this->voidDebit(['idempotency_key' => 'k2'] + $form)->assertOk()->assertJson(['status' => 'debited']);
        $this->voidDebit(['idempotency_key' => 'k2', 'points' => 200] + $form)->assertStatus(409)->assertJson(['error' => 'idempotency_conflict']);

        $this->assertSame(900, $this->wallet($alice)[0]);
        $this->assertSame(1, $this->ledgerCount($alice, 'game_debit_void'), '只留一筆作廢紀錄');
        $this->assertLedgerConsistent();
    }

    public function test_round_without_debit_is_voided_after_deadline_and_late_debit_cannot_charge(): void
    {
        $alice = $this->player('alice', 1000);
        $this->failingPaths = ['/api/points/debit'];
        $this->spin($alice, 1000)->assertJson(['state' => 'processing']);
        $round = WheelRound::where('status', 'debit_pending')->sole();

        // 期限內只重試扣點，不作廢。
        $this->travel(4)->minutes();
        $this->wheel()->reconcile();
        $this->assertSame('debit_pending', $round->fresh()->status);

        $this->travel(2)->minutes();
        $this->wheel()->reconcile();
        $rejected = $round->fresh();
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('timeout', $rejected->reject_reason);

        // 先前卡住的扣點請求這時才送達。
        $this->failingPaths = [];
        $this->debit(['player_no' => $alice->player_no, 'points' => 100, 'idempotency_key' => $round->debitKey(), 'purpose' => '轉盤下注'])
            ->assertStatus(409)->assertJson(['error' => 'debit_voided']);

        $this->assertSame([1000, 0], $this->wallet($alice));
        $this->assertSame(0, $this->ledgerCount($alice, 'game_debit'));
        $this->assertLedgerConsistent();

        $this->actingAs($alice, 'game')->get(route('game.wheel.rounds'))->assertOk()->assertSee('處理逾時，這一局沒有成立，也沒有扣點');
        $this->actingAs($this->admin('reviewer'), 'admin')->get(route('admin.minigame'))->assertOk()->assertSee('扣點逾時，已作廢');
    }

    public function test_debit_that_landed_late_is_kept_and_round_settles_after_deadline(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->begin($alice, 500);

        // 扣點成功了，但回應遺失，局停在 debit_pending 超過期限。
        $this->debit(['player_no' => $alice->player_no, 'points' => 100, 'idempotency_key' => $round->debitKey(), 'purpose' => '轉盤下注'])->assertOk();
        $this->travel(6)->minutes();
        $this->wheel()->reconcile();

        $this->assertSame('settled', $round->fresh()->status);
        $this->assertSame(1400, $this->wallet($alice)[0]);
        $this->assertSame(0, $this->ledgerCount($alice, 'game_debit_void'));
        $this->assertLedgerConsistent();
    }

    public function test_insufficient_points_voids_before_rejecting_so_an_in_flight_debit_cannot_land(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->begin($alice, 0, 1000);

        // 這一局的扣點請求還在路上，玩家的點數先被用掉了。
        app(\App\DemoGame\Services\WalletService::class)->debit($alice->id, 1000, 'admin_adjust', 'drain', '測試');
        $this->assertSame('rejected', $this->wheel()->advance($round)->status);

        // 點數補回後，那筆扣點才送達，不能扣到點數。
        app(\App\DemoGame\Services\WalletService::class)->credit($alice->id, 1000, 'admin_adjust', 'refill', '測試');
        $this->debit(['player_no' => $alice->player_no, 'points' => 1000, 'idempotency_key' => $round->debitKey(), 'purpose' => '轉盤下注'])
            ->assertStatus(409)->assertJson(['error' => 'debit_voided']);

        $this->assertSame(1000, $this->wallet($alice)[0]);
        $this->assertLedgerConsistent();
    }

    public function test_void_outage_keeps_round_pending_until_it_can_be_confirmed(): void
    {
        $alice = $this->player('alice', 1000);
        $this->failingPaths = ['/api/points/debit', '/api/points/debit/void'];
        $this->spin($alice, 200)->assertJson(['state' => 'processing']);

        $this->travel(6)->minutes();
        $this->wheel()->reconcile();
        $this->assertSame(1, WheelRound::where('status', 'debit_pending')->count(), '確認不了帳本時不能判定失敗');

        $this->failingPaths = [];
        $this->travel(2)->minutes();
        $this->wheel()->reconcile();

        $this->assertSame('timeout', WheelRound::where('status', 'rejected')->sole()->reject_reason);
        $this->assertSame(1000, $this->wallet($alice)[0]);
        $this->assertLedgerConsistent();
    }

    public function test_payout_has_no_deadline_and_keeps_retrying(): void
    {
        $alice = $this->player('alice', 1000);
        $this->failingPaths = ['/api/points/credit'];
        $this->spin($alice, 500)->assertJson(['state' => 'crediting']);

        $this->travel(30)->minutes();
        $this->wheel()->reconcile();
        $this->assertSame(1, WheelRound::where('status', 'payout_pending')->count());

        $this->failingPaths = [];
        $this->travel(2)->minutes();
        $this->wheel()->reconcile();

        $this->assertSame(1400, $this->wallet($alice)[0]);
        $this->assertSame(0, $this->ledgerCount($alice, 'game_debit_void'));
        $this->assertLedgerConsistent();
    }

    public function test_suspended_player_only_sees_the_fixed_message(): void
    {
        $alice = $this->player('alice', 1000);
        $this->suspend($alice);

        $this->actingAs($alice->fresh(), 'game')->get(route('game.wheel'))
            ->assertRedirect(route('game.login'))
            ->assertSessionHasErrors(['username' => PlayerMessages::SUSPENDED]);
    }

    // 竄改

    public function test_trigger_blocks_changes_after_the_round_starts(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->begin($alice, 500);

        $attempts = [
            ['bet_points' => 1000],
            ['payout_points' => 999999],
            ['multiplier_pct' => 1000],
            ['roll' => 99],
            ['client_seed' => 'changed'],
            ['integrity_mac' => str_repeat('0', 64)],
            ['server_seed_hash' => str_repeat('0', 64)],
            ['player_no' => 'POTHER'],
            ['status' => 'settled'],
            ['status' => 'committed'],
        ];

        foreach ($attempts as $changes) {
            $this->assertRejectedByDatabase(fn () => DB::table('minigame_rounds')->where('id', $round->id)->update($changes), json_encode($changes));
        }
        $this->assertRejectedByDatabase(fn () => DB::table('minigame_rounds')->where('id', $round->id)->delete(), 'delete');

        $settled = $this->wheel()->advance($round);
        $this->assertSame('settled', $settled->status);
        $this->assertRejectedByDatabase(fn () => DB::table('minigame_rounds')->where('id', $round->id)->update(['updated_at' => now()]), 'finished');
    }

    public function test_tampered_round_is_held_without_payout(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->begin($alice, 0);

        $this->tamper($round, ['payout_points' => 10000, 'multiplier_pct' => 1000, 'roll' => 99]);
        $held = $this->wheel()->advance($round);

        $this->assertSame('integrity_hold', $held->status);
        $this->assertSame(1000, $this->wallet($alice)[0], '檢查碼不符時連扣點都不做');
        $this->assertSame(0, $this->ledgerCount($alice, 'game_credit'));
        $this->assertSame(1, AuditLog::where('action', 'minigame.integrity_hold')->where('subject_id', $round->id)->count());
        $this->assertNotNull(AuditLog::where('action', 'minigame.integrity_hold')->first()->note);

        $this->actingAs($alice, 'game')->getJson(route('game.wheel.show', $round->public_id))
            ->assertOk()->assertJson(['state' => 'held', 'message' => PlayerMessages::ROUND_HELD])
            ->assertJsonMissingPath('roll')->assertJsonMissingPath('server_seed')->assertJsonMissingPath('payout');

        // 不會再被排程處理，也不能再變動。
        $this->travel(2)->minutes();
        $this->wheel()->reconcile();
        $this->assertSame('integrity_hold', $round->fresh()->status);
        $this->assertSame(1000, $this->wallet($alice)[0]);
    }

    public function test_tampering_during_outage_is_caught_before_payout(): void
    {
        $alice = $this->player('alice', 1000);
        $this->failingPaths = ['/api/points/credit'];
        $this->spin($alice, 50)->assertOk()->assertJson(['state' => 'crediting']);
        $round = WheelRound::where('status', 'payout_pending')->sole();

        // 斷線期間把獎勵改大，連線恢復後不能被接受。
        $this->tamper($round, ['payout_points' => 1000]);
        $this->failingPaths = [];
        $this->travel(2)->minutes();
        $this->wheel()->reconcile();

        $this->assertSame('integrity_hold', $round->fresh()->status);
        $this->assertSame(900, $this->wallet($alice)[0]);
        $this->assertSame(0, $this->ledgerCount($alice, 'game_credit'));
        $this->assertLedgerConsistent();
    }

    public function test_tampered_seed_is_detected(): void
    {
        $alice = $this->player('alice', 1000);

        $committed = $this->committed($alice);
        $this->tamper($committed, ['server_seed' => encrypt(str_repeat('b', 64), false)]);
        [$held] = $this->wheel()->begin($alice, $committed->public_id, 100, 'abcdefgh');
        $this->assertSame('integrity_hold', $held->status);

        $unreadable = $this->committed($alice);
        $this->tamper($unreadable, ['server_seed' => 'not-a-valid-ciphertext']);
        $this->assertFalse(RoundIntegrity::seedMatches($unreadable->fresh()));

        $this->assertSame(1000, $this->wallet($alice)[0]);
    }

    public function test_integrity_key_is_not_the_raw_app_key(): void
    {
        $alice = $this->player('alice', 1000);
        $round = $this->begin($alice, 100);

        $this->assertTrue(RoundIntegrity::isValid($round));

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertSame($round->integrity_mac, $round->fresh()->integrity_mac);
        $this->assertNotSame($round->integrity_mac, RoundIntegrity::mac($round->only(['public_id', 'game_player_id', 'player_no', 'server_seed_hash', 'client_seed', 'bet_points', 'roll', 'multiplier_pct', 'payout_points'])));
    }

    // 紀錄頁

    public function test_rounds_page_reveals_seed_only_after_settlement(): void
    {
        $alice = $this->player('alice', 1000);
        $settled = $this->spin($alice, 500)->json();
        $this->failingPaths = ['/api/points/credit'];
        $this->spin($alice, 200)->assertJson(['state' => 'crediting']);
        $pending = WheelRound::where('status', 'payout_pending')->sole();

        $this->actingAs($alice, 'game')->get(route('game.wheel.rounds'))->assertOk()
            ->assertSee($settled['server_seed'])
            ->assertDontSee($pending->server_seed)
            ->assertSee('入帳中');

        $this->actingAs($alice, 'game')->get(route('game.ledger'))->assertOk()->assertSee('遊戲獎勵')->assertSee('遊戲扣點');
    }

    // 後台

    public function test_only_supervisor_with_totp_can_toggle_and_it_is_audited(): void
    {
        $reviewer = $this->admin('reviewer');
        $supervisor = $this->admin('supervisor');
        $form = ['enabled' => '0', 'reason' => '維護'];

        $this->actingAs($reviewer, 'admin')->post(route('admin.minigame.toggle'), $form + ['code' => '123456'])->assertForbidden();
        $this->actingAs($supervisor, 'admin')->post(route('admin.minigame.toggle'), $form + ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($supervisor, 'admin')->post(route('admin.minigame.toggle'), ['enabled' => '0', 'code' => $this->totp($supervisor)])->assertSessionHasErrors('reason');
        $this->assertTrue(MiniGameSetting::wheelEnabled());

        $this->actingAs($supervisor, 'admin')->post(route('admin.minigame.toggle'), $form + ['code' => $this->totp($supervisor)])->assertSessionHasNoErrors();

        $this->assertFalse(MiniGameSetting::wheelEnabled());
        $log = AuditLog::where('action', 'minigame.disabled')->sole();
        $this->assertSame('supervisor', $log->actor);
        $this->assertSame('維護', $log->note);

        $this->actingAs($supervisor, 'admin')->post(route('admin.minigame.toggle'), ['enabled' => '1', 'reason' => '恢復', 'code' => $this->totp($supervisor)]);
        $this->assertTrue(MiniGameSetting::wheelEnabled());
        $this->assertSame(1, AuditLog::where('action', 'minigame.enabled')->count());
    }

    public function test_disabled_wheel_refuses_new_spins_but_finishes_started_rounds(): void
    {
        $alice = $this->player('alice', 1000);
        $started = $this->begin($alice, 500);
        MiniGameSetting::whereKey(1)->update(['wheel_enabled' => false]);

        $this->assertSame('settled', $this->wheel()->advance($started)->status);
        $this->assertSame(1400, $this->wallet($alice)[0]);

        $this->spin($alice, 500)->assertStatus(409)->assertJson(['message' => '轉盤目前暫停服務']);
        $this->assertSame(1400, $this->wallet($alice)[0]);

        $this->actingAs($alice, 'game')->get(route('game.wheel'))->assertOk()->assertSee('轉盤目前暫停服務');
        $this->actingAs($alice, 'game')->get(route('game.wheel.rounds'))->assertOk();
    }

    public function test_admin_page_shows_records_stats_and_hides_unsettled_seeds(): void
    {
        $alice = $this->player('alice', 5000);
        $this->spin($alice, 200)->assertJson(['state' => 'settled']);
        $settled = WheelRound::where('status', 'settled')->sole();

        $held = $this->begin($alice, 0);
        $this->tamper($held, ['payout_points' => 5000]);
        $this->wheel()->advance($held);

        $page = $this->actingAs($this->admin('reviewer'), 'admin')->get(route('admin.minigame'))->assertOk()
            ->assertSee('200.00%')
            ->assertSee('95%')
            ->assertSee($settled->server_seed)
            ->assertDontSee($held->fresh()->server_seed)
            ->assertSee('檢查碼不符')
            ->assertSee('沒有扣點或發獎紀錄')
            ->assertSee('核對通過');
        $this->assertStringNotContainsString('停用轉盤', $page->getContent());

        $this->actingAs($this->admin('reviewer'), 'admin')->get(route('admin.minigame', ['player_no' => 'PNONE']))->assertOk()->assertSee('沒有符合的紀錄');
        $this->actingAs($this->admin('reviewer'), 'admin')->get(route('admin.minigame', ['status' => 'nope']))->assertSessionHasErrors('status');
        $this->actingAs($this->admin('supervisor'), 'admin')->get(route('admin.minigame'))->assertOk()->assertSee('停用轉盤');
    }

    public function test_overdue_round_is_flagged_in_admin(): void
    {
        $alice = $this->player('alice', 1000);
        $this->failingPaths = ['/api/points/debit'];
        $this->spin($alice, 100)->assertJson(['state' => 'processing']);

        $this->travel(11)->minutes();

        $this->actingAs($this->admin('reviewer'), 'admin')->get(route('admin.minigame'))->assertOk()->assertSee('仍未完成')->assertSee('逾時');
    }

    public function test_demo_reset_clears_rounds_but_keeps_the_switch(): void
    {
        $alice = $this->player('alice', 1000);
        $this->spin($alice, 100)->assertOk();
        MiniGameSetting::whereKey(1)->update(['wheel_enabled' => false]);

        app(DemoTools::class)->reset($this->admin('supervisor'));

        $this->assertSame(0, WheelRound::count());
        $this->assertFalse(MiniGameSetting::wheelEnabled());
    }

    private function assertRejectedByDatabase(callable $write, string $label): void
    {
        try {
            DB::transaction($write); // 用儲存點，失敗後測試的交易還能繼續。
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("資料庫應該拒絕這個變更：$label");
    }
}
