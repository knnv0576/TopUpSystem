<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Player;
use App\Models\PointGrant;
use App\Payments\MockGateway;
use App\Services\PlayerSuspensionService;
use App\Support\PlayerMessages;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PlaysWheel;
use Tests\TestCase;

class GameTopupTest extends TestCase
{
    use PlaysWheel;

    private const SUCCESS_CARD = '4111 1111 1111 1111';

    private function card(string $number = self::SUCCESS_CARD): array
    {
        return ['card_name' => 'TEST USER', 'card_number' => $number, 'card_expiry' => now()->addYears(2)->format('m/y'), 'card_cvv' => '123'];
    }

    private function createTopup($player, int $packageId, ?string $requestId = null, array $extra = [], string $method = 'card')
    {
        return $this->actingAs($player, 'game')->postJson(route('game.wheel.topups'), [
            'package_id' => $packageId,
            'request_id' => $requestId ?? (string) Str::uuid(),
            'method' => $method,
        ] + $extra);
    }

    private function payTopup($player, string $orderNo, array $payment)
    {
        return $this->actingAs($player, 'game')->postJson(route('game.wheel.topups.pay', $orderNo), $payment);
    }

    /** 建立訂單並用成功的測試卡付款，回傳建立訂單的回應。 */
    private function topup($player, int $packageId, ?string $requestId = null, array $extra = [])
    {
        $response = $this->createTopup($player, $packageId, $requestId, $extra);
        if ($response->json('state') === 'awaiting_payment') {
            $this->payTopup($player, $response->json('order_no'), $this->card() + $extra)->assertOk();
        }

        return $response;
    }

    public function test_successful_topup_updates_every_table(): void
    {
        $alice = $this->player('alice');
        $package = $this->package(100);

        $orderNo = $this->topup($alice, $package->id)->assertCreated()->json('order_no');

        $this->actingAs($alice, 'game')->getJson(route('game.wheel.topups.show', $orderNo))
            ->assertOk()->assertJson(['state' => 'success', 'available' => 10000]);

        $order = Order::where('order_no', $orderNo)->sole();
        $this->assertSame('fulfilled', $order->status);
        $this->assertSame('game', $order->source);
        $this->assertSame(100, $order->amount);
        $this->assertSame(10000, $order->points);
        $this->assertSame(1, Payment::where('order_id', $order->id)->where('status', 'succeeded')->count());
        $this->assertSame(1, DB::table('gateway_events')->where('type', 'payment.succeeded')->count());
        $this->assertSame('granted', PointGrant::where('order_id', $order->id)->sole()->status);
        $this->assertSame(1, $this->ledgerCount($alice, 'topup'));
        $this->assertSame([10000, 0], $this->wallet($alice));
        $this->assertSame(1, AuditLog::where('action', 'payment.succeeded')->count());
        $this->assertLedgerConsistent();
    }

    public function test_creating_order_does_not_pay_until_payment_is_submitted(): void
    {
        $alice = $this->player('alice');
        $package = $this->package(100);

        $created = $this->createTopup($alice, $package->id)->assertCreated()
            ->assertJson(['state' => 'awaiting_payment', 'amount' => 100, 'points' => 10000, 'payment' => ['method' => 'card']]);
        $this->assertNotEmpty($created->json('payment.test_cards'));
        $this->assertSame(0, Payment::count());
        $this->assertSame([0, 0], $this->wallet($alice));

        // 格式錯誤：回傳欄位錯誤，不付款，也不帶出輸入的卡號。
        $orderNo = $created->json('order_no');
        $invalid = $this->payTopup($alice, $orderNo, $this->card('4111 1111 1111 1112'))->assertStatus(422)
            ->assertJsonValidationErrors(['card_number'], 'errors');
        $this->assertStringNotContainsString('4111', $invalid->getContent());
        $this->assertSame(0, Payment::count());

        $this->payTopup($alice, $orderNo, $this->card())->assertOk()->assertJson(['state' => 'success', 'available' => 10000]);
        $order = Order::where('order_no', $orderNo)->sole();
        $this->assertSame(['fulfilled', 'card'], [$order->status, $order->payment_method]);
        $this->assertNotNull($order->payment_submitted_at);

        // 付過就不能再付。
        $this->payTopup($alice, $orderNo, $this->card())->assertStatus(409);
        $this->assertSame(1, Payment::count());
        $this->assertSame([10000, 0], $this->wallet($alice));

        // 卡號沒有留在任何紀錄裡。
        foreach (['orders', 'payments', 'gateway_events', 'audit_logs', 'jobs'] as $table) {
            $this->assertFalse(DB::table($table)->get()->contains(fn ($row) => str_contains(json_encode($row), '4111111111111111')), $table);
        }
    }

    public function test_card_number_decides_result_and_other_methods_work(): void
    {
        $alice = $this->player('alice');
        $package = $this->package(100);

        // 發卡行拒絕：失敗，不入點。
        $declined = $this->createTopup($alice, $package->id)->json('order_no');
        $this->payTopup($alice, $declined, $this->card('4000 0000 0000 0002'))->assertOk()->assertJson(['state' => 'failed']);

        // ATM：帳號與金額都要對。
        $atm = $this->createTopup($alice, $package->id, null, [], 'atm')->assertCreated()->assertJson(['payment' => ['method' => 'atm', 'bank' => '999']]);
        $account = $atm->json('payment.account');
        $this->payTopup($alice, $atm->json('order_no'), ['atm_bank' => '999', 'atm_account' => $account, 'atm_amount' => '99'])
            ->assertStatus(422)->assertJsonValidationErrors(['atm_amount'], 'errors');
        $this->payTopup($alice, $atm->json('order_no'), ['atm_bank' => '999', 'atm_account' => $account, 'atm_amount' => '100'])->assertOk()->assertJson(['state' => 'success']);

        // 超商代碼。
        $cvs = $this->createTopup($alice, $package->id, null, [], 'cvs')->assertCreated();
        $this->payTopup($alice, $cvs->json('order_no'), ['cvs_code' => strtolower($cvs->json('payment.code')), 'cvs_amount' => '100'])->assertOk()->assertJson(['state' => 'success']);

        $this->assertSame([20000, 0], $this->wallet($alice));
        $this->assertLedgerConsistent();
    }

    public function test_cvs_over_limit_is_refused_when_creating(): void
    {
        $alice = $this->player('alice');
        $large = $this->package(\App\Payments\MockCheckout::CVS_LIMIT + 1);

        $this->createTopup($alice, $large->id, null, [], 'cvs')->assertStatus(422)->assertJson(['message' => '超商代碼單筆上限 20,000 元，請改用其他付款方式']);
        $this->createTopup($alice, $large->id, null, [], 'bitcoin')->assertStatus(422)->assertJsonValidationErrors('method');
        $this->assertSame(0, Order::count());
    }

    public function test_abandoned_order_expires_without_looking_failed(): void
    {
        $alice = $this->player('alice');
        $orderNo = $this->createTopup($alice, $this->package(100)->id)->json('order_no');

        $this->travel(config('topup.order_ttl_minutes') + 1)->minutes();
        app(\App\Services\OrderService::class)->expireDue();

        $this->actingAs($alice, 'game')->getJson(route('game.wheel.topups.show', $orderNo))->assertJson(['state' => 'expired']);
        $this->payTopup($alice, $orderNo, $this->card())->assertStatus(409);
        $this->assertSame([], Order::where('source', 'game')->failedForPlayer()->pluck('order_no')->all());
    }

    public function test_player_cannot_pay_another_players_order(): void
    {
        $alice = $this->player('alice');
        $bob = $this->player('bob');
        $orderNo = $this->createTopup($alice, $this->package(100)->id)->json('order_no');

        $this->payTopup($bob, $orderNo, $this->card())->assertNotFound();
        $this->assertSame(0, Payment::count());
    }

    public function test_repeated_request_creates_one_order_and_one_payment(): void
    {
        $alice = $this->player('alice');
        $package = $this->package(100);
        $requestId = (string) Str::uuid();

        $first = $this->topup($alice, $package->id, $requestId)->assertCreated()->json('order_no');
        $second = $this->topup($alice, $package->id, $requestId)->assertCreated()->json('order_no');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame([10000, 0], $this->wallet($alice));
    }

    public function test_same_request_id_from_different_players_stays_separate(): void
    {
        $alice = $this->player('alice');
        $bob = $this->player('bob');
        $package = $this->package(100);
        $requestId = (string) Str::uuid();

        $this->topup($alice, $package->id, $requestId)->assertCreated();
        $this->topup($bob, $package->id, $requestId)->assertCreated();

        $this->assertSame(2, Order::count());
        $this->assertSame([10000, 0], $this->wallet($bob));
    }

    public function test_client_cannot_choose_scenario_or_amount(): void
    {
        $alice = $this->player('alice');
        $package = $this->package(100);

        $orderNo = $this->topup($alice, $package->id, null, ['scenario' => 'amount_mismatch', 'amount' => 1, 'points' => 999999])
            ->assertCreated()->json('order_no');

        $this->actingAs($alice, 'game')->getJson(route('game.wheel.topups.show', $orderNo))->assertJson(['state' => 'success']);
        $this->assertSame(100, Order::where('order_no', $orderNo)->sole()->amount);
        $this->assertSame([10000, 0], $this->wallet($alice));
    }

    public function test_failure_set_from_demo_panel_applies_once_and_player_sees_fixed_message(): void
    {
        $alice = $this->player('alice');
        $package = $this->package(100);
        $supervisor = $this->admin('supervisor');

        $this->actingAs($this->admin('reviewer'), 'admin')->post(route('admin.demo.next-game-topup'), ['result' => 'failed'])->assertForbidden();
        $this->actingAs($supervisor, 'admin')->post(route('admin.demo.next-game-topup'), ['result' => 'amount_mismatch'])->assertSessionHasErrors('result');
        $this->actingAs($supervisor, 'admin')->post(route('admin.demo.next-game-topup'), ['result' => 'failed'])->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::where('action', 'demo.next_game_topup')->count());

        $orderNo = $this->topup($alice, $package->id)->assertCreated()->json('order_no');
        $this->actingAs($alice, 'game')->getJson(route('game.wheel.topups.show', $orderNo))
            ->assertOk()->assertExactJson([
                'order_no' => $orderNo,
                'state' => 'failed',
                'message' => PlayerMessages::TOPUP_FAILED,
                'available' => 0,
            ]);

        $this->assertSame([0, 0], $this->wallet($alice));
        $this->assertSame(0, $this->ledgerCount($alice, 'topup'));
        $this->assertSame(1, AuditLog::where('action', 'payment.failed')->count());
        $this->assertNull(Cache::get(MockGateway::NEXT_GAME_TOPUP_CACHE_KEY));

        // 玩家的訂單頁也是同一句，不顯示實際原因。
        $this->actingAs($alice, 'game')->get(route('game.orders'))->assertOk()->assertSee(PlayerMessages::TOPUP_FAILED)->assertDontSee('付款失敗');

        // 後台看得到實際狀態。
        $this->actingAs($supervisor, 'admin')->get(route('admin.minigame', ['topup' => 'failed']))->assertOk()->assertSee($orderNo)->assertSee('付款失敗');

        // 下一次回到成功。
        $next = $this->topup($alice, $package->id)->assertCreated()->json('order_no');
        $this->actingAs($alice, 'game')->getJson(route('game.wheel.topups.show', $next))->assertJson(['state' => 'success']);
        $this->assertSame([10000, 0], $this->wallet($alice));

        $this->actingAs($supervisor, 'admin')->get(route('admin.minigame', ['topup' => 'failed']))->assertSee($orderNo)->assertDontSee($next);
    }

    public function test_every_failure_kind_looks_the_same_to_the_player(): void
    {
        $alice = $this->player('alice');
        $player = Player::forGame($alice->id);
        $package = $this->package(100);
        $make = fn (array $attributes = []) => Order::create($attributes + [
            'order_no' => 'T'.Str::upper(Str::random(12)),
            'player_id' => $player->id,
            'package_id' => $package->id,
            'package_name' => $package->name,
            'amount' => 100,
            'points' => 10000,
            'currency' => 'TWD',
            'status' => 'pending',
            'source' => 'game',
            'payment_method' => 'card',
            'payment_submitted_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $failed = $make(['status' => 'failed']);
        $expired = $make(['status' => 'expired']);
        $shopExpired = $make(['status' => 'expired', 'source' => 'shop', 'payment_submitted_at' => null]);
        $abandoned = $make(['status' => 'expired', 'payment_submitted_at' => null]);
        $grantFailed = $make(['status' => 'paid']);
        PointGrant::create(['order_id' => $grantFailed->id, 'player_id' => $player->id, 'points' => 10000, 'status' => 'failed']);
        $mismatch = $make();
        Payment::create(['order_id' => $mismatch->id, 'provider' => 'mock', 'provider_txn_id' => 'X1', 'amount' => 101, 'currency' => 'TWD', 'status' => 'amount_mismatch']);
        $processing = $make();
        $unpaid = $make(['payment_submitted_at' => null]);

        foreach ([$failed, $expired, $grantFailed, $mismatch] as $order) {
            $this->assertSame(PlayerMessages::TOPUP_FAILED, $order->playerStatusLabel(), $order->status);
            $this->actingAs($alice, 'game')->getJson(route('game.wheel.topups.show', $order->order_no))->assertJson(['state' => 'failed', 'message' => PlayerMessages::TOPUP_FAILED]);
        }

        // 未付款到期不算失敗（充值網站，或彈窗沒付款就關掉）；處理中也不能先說失敗。
        $this->assertSame('已過期', $shopExpired->playerStatusLabel());
        $this->assertSame('已過期', $abandoned->playerStatusLabel());
        $this->actingAs($alice, 'game')->getJson(route('game.wheel.topups.show', $abandoned->order_no))->assertJson(['state' => 'expired']);
        $this->actingAs($alice, 'game')->getJson(route('game.wheel.topups.show', $processing->order_no))->assertJson(['state' => 'processing']);
        $this->actingAs($alice, 'game')->getJson(route('game.wheel.topups.show', $unpaid->order_no))->assertJson(['state' => 'awaiting_payment']);

        // 列表篩選與單筆判定要一致。
        $this->assertEqualsCanonicalizing(
            [$failed->id, $expired->id, $grantFailed->id, $mismatch->id],
            Order::where('source', 'game')->failedForPlayer()->pluck('id')->all(),
        );
    }

    public function test_shop_order_page_uses_the_player_label(): void
    {
        $alice = $this->player('alice');
        $order = $this->order($alice, 100);
        $order->update(['status' => 'failed']);

        $this->actingAs(Player::forGame($alice->id), 'shop')
            ->withSession([\App\Http\Middleware\EnsureShopPlayer::LOGIN_AT_KEY => now()->timestamp])
            ->get(route('shop.orders.show', $order->order_no))
            ->assertOk()->assertSee(PlayerMessages::TOPUP_FAILED)->assertDontSee('付款失敗');
    }

    public function test_inactive_or_unknown_package_is_refused(): void
    {
        $alice = $this->player('alice');
        $package = $this->package(100);
        $package->update(['active' => false]);

        $this->topup($alice, $package->id)->assertStatus(422)->assertJson(['message' => '這個方案已下架']);
        $this->topup($alice, 999999)->assertStatus(422)->assertJson(['message' => '找不到這個方案']);
        $this->assertSame(0, Order::count());

        $this->actingAs($alice, 'game')->get(route('game.wheel'))->assertOk()->assertDontSee($package->name);
    }

    public function test_suspended_player_cannot_create_orders(): void
    {
        $alice = $this->player('alice');
        $package = $this->package(100);
        app(PlayerSuspensionService::class)->suspend(Player::forGame($alice->id), $this->admin('supervisor'), '測試');

        $this->signedPost("/api/topup/players/{$alice->id}/game-orders", ['request_id' => (string) Str::uuid(), 'package_id' => $package->id, 'method' => 'card'], config('topup.topup_api_secret'))
            ->assertStatus(422)->assertJson(['error' => PlayerMessages::SUSPENDED]);

        $this->assertSame(0, Order::count());
    }

    public function test_player_cannot_read_another_players_order(): void
    {
        $alice = $this->player('alice');
        $bob = $this->player('bob');
        $orderNo = $this->topup($alice, $this->package(100)->id)->json('order_no');

        $this->actingAs($bob, 'game')->getJson(route('game.wheel.topups.show', $orderNo))->assertNotFound();
    }

    public function test_outage_reports_unknown_and_retry_with_same_request_id_is_safe(): void
    {
        $alice = $this->player('alice');
        $package = $this->package(100);
        $requestId = (string) Str::uuid();

        $this->failingPaths = ['/api/topup/players/*/game-orders'];
        $this->topup($alice, $package->id, $requestId)->assertStatus(503);
        $this->assertSame(0, Order::count());

        $this->failingPaths = [];
        $this->topup($alice, $package->id, $requestId)->assertCreated();
        $this->assertSame(1, Order::count());
        $this->assertSame([10000, 0], $this->wallet($alice));
    }

    public function test_game_page_still_loads_when_topup_side_is_down(): void
    {
        $alice = $this->player('alice', 1000);
        $this->failingPaths = ['/api/topup/packages'];

        $this->actingAs($alice, 'game')->get(route('game.wheel'))->assertOk()->assertSee('目前無法取得充值方案');
    }

    public function test_demo_reset_forgets_pending_topup_result(): void
    {
        $supervisor = $this->admin('supervisor');
        $this->actingAs($supervisor, 'admin')->post(route('admin.demo.next-game-topup'), ['result' => 'failed']);

        app(\App\Services\DemoTools::class)->reset($supervisor);

        $this->assertNull(Cache::get(MockGateway::NEXT_GAME_TOPUP_CACHE_KEY));
    }
}
