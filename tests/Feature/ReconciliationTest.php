<?php

namespace Tests\Feature;

use App\DemoGame\Services\WalletService;
use App\MiniGame\Models\WheelRound;
use App\MiniGame\Services\ReconciliationService;
use App\Models\Order;
use App\Models\Player;
use App\Services\RefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PlaysWheel;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use PlaysWheel;

    private function check(): array
    {
        return app(ReconciliationService::class)->run();
    }

    private function assertFinds(string $needle): void
    {
        $result = $this->check();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString($needle, implode("\n", $result['issues']));
    }

    public function test_full_flow_from_topup_to_refund_passes(): void
    {
        $alice = $this->player('alice');
        $package = $this->package(100);

        $orderNo = $this->actingAs($alice, 'game')->postJson(route('game.wheel.topups'), [
            'package_id' => $package->id,
            'request_id' => (string) Str::uuid(),
            'method' => 'card',
        ])->assertCreated()->json('order_no');
        $this->actingAs($alice, 'game')->postJson(route('game.wheel.topups.pay', $orderNo), [
            'card_name' => 'TEST USER', 'card_number' => '4111111111111111', 'card_expiry' => now()->addYear()->format('m/y'), 'card_cvv' => '123',
        ])->assertOk();
        $this->assertSame('fulfilled', Order::where('order_no', $orderNo)->sole()->status);

        $this->spin($alice, 1000, 1000)->assertOk()->assertJson(['state' => 'settled', 'payout' => 10000]);
        $this->spin($alice, 0, 100)->assertOk()->assertJson(['state' => 'settled', 'payout' => 0]);
        $this->assertSame(18900, $this->wallet($alice)[0]);

        // 贏到的點數可以走完退款。
        $refund = $this->submitRefund($alice, 10000);
        $service = app(RefundService::class);
        $service->reviewApprove($refund, $this->admin('reviewer'));
        $service->supervisorApprove($refund->fresh(), $this->admin('supervisor'));

        $this->assertSame('paid_out', $refund->fresh()->status);
        $this->assertSame([8900, 0], $this->wallet($alice));
        $this->assertLedgerConsistent();

        $this->assertTrue($this->check()['ok'], implode("\n", $this->check()['issues']));
        $this->artisan('minigame:check')->assertExitCode(0);
    }

    public function test_check_does_not_modify_anything(): void
    {
        $alice = $this->player('alice', 1000);
        $this->spin($alice, 200)->assertOk();
        DB::table('game_wallets')->where('player_id', $alice->id)->update(['balance' => 5]);

        $snapshot = fn () => [
            DB::table('game_wallets')->get()->toArray(),
            DB::table('game_point_ledger')->get()->toArray(),
            DB::table('minigame_rounds')->get()->toArray(),
            DB::table('orders')->get()->toArray(),
        ];
        $before = $snapshot();

        $this->check();
        $this->artisan('minigame:check')->assertExitCode(1);

        $this->assertEquals($before, $snapshot());
    }

    public function test_detects_wallet_that_differs_from_ledger(): void
    {
        $alice = $this->player('alice', 1000);
        DB::table('game_wallets')->where('player_id', $alice->id)->update(['balance' => 2000]);

        $this->assertFinds('與帳本加總');
    }

    public function test_detects_missing_debit_for_round(): void
    {
        $alice = $this->player('alice', 1000);
        $this->spin($alice, 200)->assertOk();
        $round = WheelRound::where('status', 'settled')->sole();

        DB::table('game_point_ledger')->where('idempotency_key', "debit:{$alice->id}:{$round->debitKey()}")->delete();

        $this->assertFinds('下注額');
    }

    public function test_detects_missing_credit_for_settled_round(): void
    {
        $alice = $this->player('alice', 1000);
        $this->spin($alice, 200)->assertOk();
        $round = WheelRound::where('status', 'settled')->sole();

        DB::table('game_point_ledger')->where('idempotency_key', "credit:{$alice->id}:{$round->debitKey()}")->delete();

        $this->assertFinds('獎勵');
    }

    public function test_detects_orphan_game_credit(): void
    {
        $alice = $this->player('alice');
        app(WalletService::class)->credit($alice->id, 500, 'game_credit', "credit:{$alice->id}:no-such-round", '偽造');

        $this->assertFinds('找不到對應的局');
    }

    public function test_detects_ledger_movement_on_rejected_round(): void
    {
        $alice = $this->player('alice', 50);
        $this->spin($alice, 100)->assertJson(['state' => 'rejected']);
        $round = WheelRound::where('status', 'rejected')->sole();

        app(WalletService::class)->credit($alice->id, 100, 'task', "seed2:{$alice->id}", '補點');

        // 正常流程的晚到扣點會被作廢紀錄擋下，這裡繞過 WalletService 直接寫入，模擬資料被改。
        DB::table('game_point_ledger')->insert([
            'player_id' => $alice->id, 'type' => 'game_debit', 'delta_balance' => -100, 'delta_frozen' => 0,
            'balance_after' => 50, 'frozen_after' => 0, 'idempotency_key' => "debit:{$alice->id}:{$round->debitKey()}",
            'memo' => '遲到的扣點', 'created_at' => now(),
        ]);

        $this->assertFinds('未成立');
        $this->assertFinds('已作廢，卻仍有點數異動');
    }

    public function test_voided_round_passes_reconciliation(): void
    {
        $alice = $this->player('alice', 1000);
        $this->failingPaths = ['/api/points/debit'];
        $this->spin($alice, 200)->assertJson(['state' => 'processing']);

        $this->travel(6)->minutes();
        $this->wheel()->reconcile();

        $this->assertSame('timeout', WheelRound::where('status', 'rejected')->sole()->reject_reason);
        $result = $this->check();
        $this->assertTrue($result['ok'], implode("\n", $result['issues']));
    }

    public function test_detects_fulfilled_order_without_points(): void
    {
        $alice = $this->player('alice');
        $order = $this->order($alice, 100);
        $order->update(['status' => 'fulfilled']);

        $this->assertFinds("訂單 {$order->order_no}");
    }

    public function test_detects_topup_entry_without_order(): void
    {
        $alice = $this->player('alice');
        Player::forGame($alice->id);
        app(WalletService::class)->credit($alice->id, 500, 'topup', 'grant:order:999999', '偽造');

        $this->assertFinds('找不到對應的已付款訂單');
    }

    public function test_detects_round_changed_behind_the_trigger(): void
    {
        $alice = $this->player('alice', 1000);
        $this->spin($alice, 200)->assertOk();
        $round = WheelRound::where('status', 'settled')->sole();

        DB::statement('ALTER TABLE minigame_rounds DISABLE TRIGGER minigame_rounds_guard');
        DB::table('minigame_rounds')->where('id', $round->id)->update(['roll' => 0]);
        DB::statement('ALTER TABLE minigame_rounds ENABLE TRIGGER minigame_rounds_guard');

        $this->assertFinds('檢查碼不符');
    }

    public function test_in_progress_and_held_rounds_are_counted_separately(): void
    {
        $alice = $this->player('alice', 1000);

        $this->begin($alice, 100);
        $this->assertSame(1, $this->check()['in_progress']);
        $this->assertTrue($this->check()['ok'], '處理中不算缺帳');

        $this->wheel()->advance(WheelRound::where('status', 'debit_pending')->sole());

        $held = $this->begin($alice, 0);
        $this->tamper($held, ['payout_points' => 5000]);
        $this->wheel()->advance($held);

        $result = $this->check();
        $this->assertSame(1, $result['held']);
        $this->assertTrue($result['ok'], implode("\n", $result['issues']));
    }
}
