<?php

namespace Tests\Feature;

use App\DemoGame\Services\RedPacketService;
use App\Models\Anomaly;
use App\Models\Player;
use App\Models\PointAdjustment;
use App\Models\PointGrant;
use App\Models\RefundRequest;
use App\Payments\MockGateway;
use App\Services\FulfillmentService;
use App\Services\PlayerSuspensionService;
use App\Services\PointAdjustmentService;
use App\Services\RefundService;
use App\Support\ApiSignature;
use App\Support\SsoToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdjustmentAndSuspensionTest extends TestCase
{
    private function adjustments(): PointAdjustmentService
    {
        return app(PointAdjustmentService::class);
    }

    private function suspensions(): PlayerSuspensionService
    {
        return app(PlayerSuspensionService::class);
    }

    public function test_adjustment_needs_reviewer_then_supervisor(): void
    {
        $player = $this->player('alice', 1000);
        $shopPlayer = Player::forGame($player->id);
        $reviewer = $this->admin('reviewer');
        $supervisor = $this->admin('supervisor');

        try {
            $this->adjustments()->initiate($shopPlayer, $supervisor, 'add', 100, '補償');
            $this->fail('supervisor cannot initiate');
        } catch (RuntimeException) {
        }

        $adjustment = $this->adjustments()->initiate($shopPlayer, $reviewer, 'add', 100, '補償');
        $this->assertSame([1000, 0], $this->wallet($player));

        try {
            $this->adjustments()->approve($adjustment, $reviewer);
            $this->fail('reviewer cannot approve');
        } catch (RuntimeException) {
        }

        $this->assertTrue($this->adjustments()->approve($adjustment, $supervisor));
        $this->assertSame([1100, 0], $this->wallet($player));

        try {
            $this->adjustments()->approve($adjustment, $supervisor);
            $this->fail('cannot apply twice');
        } catch (RuntimeException) {
        }
        $this->assertSame([1100, 0], $this->wallet($player));
        $this->assertLedgerConsistent();
    }

    public function test_deduct_checks_current_available_balance_and_spares_frozen_points(): void
    {
        $player = $this->player('alice', 20000);
        $shopPlayer = Player::forGame($player->id);
        $adjustment = $this->adjustments()->initiate($shopPlayer, $this->admin('reviewer'), 'deduct', 15000, '扣回');

        // 發起之後才凍結，核准時要以當下的可用餘額判斷。
        $this->submitRefund($player, 10000);
        $this->assertSame([20000, 10000], $this->wallet($player));

        $this->assertFalse($this->adjustments()->approve($adjustment, $this->admin('supervisor')));

        $this->assertSame('rejected', $adjustment->fresh()->status);
        $this->assertSame([20000, 10000], $this->wallet($player));
    }

    public function test_adjustment_routes_require_role_and_totp(): void
    {
        $player = $this->player('alice', 1000);
        $shopPlayer = Player::forGame($player->id);
        $reviewer = $this->admin('reviewer');
        $supervisor = $this->admin('supervisor');
        $form = ['direction' => 'add', 'points' => 100, 'reason' => '補償'];

        $this->actingAs($supervisor, 'admin')->post(route('admin.players.adjust', $shopPlayer), $form)->assertForbidden();
        $this->actingAs($reviewer, 'admin')->post(route('admin.players.adjust', $shopPlayer), $form)->assertRedirect();
        $adjustment = PointAdjustment::firstOrFail();

        $this->actingAs($reviewer, 'admin')->post(route('admin.adjustments.approve', $adjustment), ['code' => '123456'])->assertForbidden();
        $this->actingAs($supervisor, 'admin')->post(route('admin.adjustments.approve', $adjustment), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertSame([1000, 0], $this->wallet($player));

        $this->actingAs($supervisor, 'admin')->post(route('admin.adjustments.approve', $adjustment), ['code' => $this->totp($supervisor)]);
        $this->assertSame([1100, 0], $this->wallet($player));
    }

    public function test_suspension_cascades_to_refunds_adjustments_and_red_packets(): void
    {
        $alice = $this->player('alice', 100000);
        $bob = $this->player('bob', 1000);
        $carol = $this->player('carol');
        $shopAlice = Player::forGame($alice->id);
        $packets = app(RedPacketService::class);

        $refund = $this->submitRefund($alice, 20000);
        $adjustment = $this->adjustments()->initiate($shopAlice, $this->admin('reviewer'), 'add', 500, '補償');
        $fromAlice = $packets->send($alice->fresh(), $carol->player_no, 300, null, (string) Str::uuid());
        $toAlice = $packets->send($bob->fresh(), $alice->player_no, 200, null, (string) Str::uuid());
        $unrelated = $packets->send($bob->fresh(), $carol->player_no, 100, null, (string) Str::uuid());

        $this->suspensions()->suspend($shopAlice, $this->admin('supervisor'), '可疑操作');

        $this->assertNotNull($shopAlice->fresh()->suspended_at);
        $this->assertNotNull($alice->fresh()->suspended_at);

        $this->assertSame('rejected', $refund->fresh()->status);
        $this->assertNull(DB::table('refund_requests')->where('id', $refund->id)->value('account_encrypted'));
        $this->assertSame('rejected', $adjustment->fresh()->status);

        $this->assertSame('returned', $fromAlice->fresh()->status);
        $this->assertSame('returned', $toAlice->fresh()->status);
        $this->assertSame('sent', $unrelated->fresh()->status);

        $this->assertSame([100000, 0], $this->wallet($alice));
        $this->assertSame([900, 0], $this->wallet($bob));
        $this->assertLedgerConsistent();
    }

    public function test_suspended_player_cannot_use_any_session_or_token(): void
    {
        $alice = $this->player('alice', 100000);
        $shopAlice = Player::forGame($alice->id);
        $token = SsoToken::issue($alice->id);

        $this->suspensions()->suspend($shopAlice, $this->admin('supervisor'), '停權');

        $this->post(route('game.login'), ['username' => 'alice', 'password' => 'Password123456'])
            ->assertSessionHasErrors('username');
        $this->assertGuest('game');

        $this->actingAs($alice->fresh(), 'game')->get(route('game.dashboard'))->assertRedirect(route('game.login'));
        $this->assertGuest('game');

        $this->actingAs($shopAlice->fresh(), 'shop')->get(route('shop.index'))->assertRedirect(route('game.login'));
        $this->assertGuest('shop');

        $this->post(route('shop.sso'), ['token' => $token])->assertRedirect(route('game.login'));
        $this->assertGuest('shop');

        $this->expectException(RuntimeException::class);
        $this->submitRefund($alice, 10000);
    }

    public function test_approved_requests_continue_when_player_is_suspended(): void
    {
        $alice = $this->player('alice', 50000);
        $refund = $this->submitRefund($alice, 20000);
        app(RefundService::class)->reviewApprove($refund, $this->admin('reviewer'));

        \Illuminate\Support\Facades\Queue::fake();
        app(RefundService::class)->supervisorApprove($refund->fresh(), $this->admin('supervisor'));

        $this->suspensions()->suspend(Player::forGame($alice->id), $this->admin('supervisor'), '停權');
        $this->assertSame('approved', $refund->fresh()->status);

        app(RefundService::class)->execute($refund->id);
        $this->assertSame('paying_out', $refund->fresh()->status);
        $this->assertSame([30000, 0], $this->wallet($alice));
    }

    public function test_cannot_approve_after_suspension(): void
    {
        $alice = $this->player('alice', 50000);
        $shopAlice = Player::forGame($alice->id);
        $refund = $this->submitRefund($alice, 20000);
        app(RefundService::class)->reviewApprove($refund, $this->admin('reviewer'));

        // 停權已發生，但競爭中的核准請求稍後才抵達。
        Player::whereKey($shopAlice->id)->update(['suspended_at' => now()]);

        try {
            app(RefundService::class)->supervisorApprove($refund->fresh(), $this->admin('supervisor'));
            $this->fail('should be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('停權', $e->getMessage());
        }
        $this->assertSame('pending_supervisor', $refund->fresh()->status);
        $this->assertSame(0, DB::table('mock_payouts')->count());
    }

    public function test_late_freeze_after_suspension_does_not_leak_frozen_points(): void
    {
        $alice = $this->player('alice', 50000);
        \Illuminate\Support\Facades\Queue::fake();
        $refund = app(RefundService::class)->submit((string) Str::uuid(), $alice->id, 20000, '812', '1234567890');
        $this->assertSame('pending_freeze', $refund->status);

        // 停權先拒絕申請；解凍工作在凍結之前執行。
        $this->suspensions()->suspend(Player::forGame($alice->id), $this->admin('supervisor'), '停權');
        (new \App\Jobs\UnfreezeRefundPoints($refund->id))->handle(app(\App\Game\GameGateway::class));

        // 舊的凍結呼叫這時才抵達玩家端。
        app(\App\Game\GameGateway::class)->freezePoints($alice->id, 20000, $refund->ref());

        $this->assertSame([50000, 0], $this->wallet($alice));
        $this->assertLedgerConsistent();
    }

    public function test_payment_after_suspension_is_held_until_manual_grant_after_unsuspend(): void
    {
        $alice = $this->player('alice');
        $shopAlice = Player::forGame($alice->id);
        $order = $this->order($alice);
        $supervisor = $this->admin('supervisor');

        $this->suspensions()->suspend($shopAlice, $supervisor, '停權');
        $this->sendWebhook($this->paymentEvent($order))->assertOk();

        $grant = PointGrant::firstOrFail();
        $this->assertSame('held_suspended', $grant->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame([0, 0], $this->wallet($alice));
        $anomaly = Anomaly::where('kind', 'grant_held_suspended')->whereNull('resolved_at')->firstOrFail();

        // 解除前不能補入。
        try {
            app(FulfillmentService::class)->retry($grant, $supervisor);
            $this->fail('cannot grant while suspended');
        } catch (RuntimeException) {
        }
        $this->assertSame([0, 0], $this->wallet($alice));

        $this->suspensions()->unsuspend($shopAlice->fresh(), $supervisor, '誤判');
        $this->assertNull($alice->fresh()->suspended_at);
        $this->assertSame('held_suspended', $grant->fresh()->status, '解除不會自動入點');

        // 補入要通過 TOTP。
        $this->actingAs($supervisor, 'admin')->post(route('admin.anomalies.retry', $anomaly), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertSame([0, 0], $this->wallet($alice));
        $this->actingAs($supervisor, 'admin')->post(route('admin.anomalies.retry', $anomaly), ['code' => $this->totp($supervisor)]);

        $this->assertSame('granted', $grant->fresh()->status);
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame([50000, 0], $this->wallet($alice));

        // 第二次補入無效。
        try {
            app(FulfillmentService::class)->retry($grant->fresh(), $supervisor);
            $this->fail('only once');
        } catch (RuntimeException) {
        }
        $this->assertSame([50000, 0], $this->wallet($alice));
        $this->assertLedgerConsistent();
    }

    public function test_unsuspend_does_not_restore_rejected_items(): void
    {
        $alice = $this->player('alice', 50000);
        $shopAlice = Player::forGame($alice->id);
        $refund = $this->submitRefund($alice, 20000);
        $supervisor = $this->admin('supervisor');

        $this->suspensions()->suspend($shopAlice, $supervisor, '停權');
        $this->suspensions()->unsuspend($shopAlice->fresh(), $supervisor, '解除');

        $this->assertSame('rejected', $refund->fresh()->status);
        $this->post(route('game.login'), ['username' => 'alice', 'password' => 'Password123456'])->assertRedirect(route('game.dashboard'));
    }

    public function test_suspension_sync_failure_still_blocks_and_can_recover(): void
    {
        $alice = $this->player('alice', 50000);
        $shopAlice = Player::forGame($alice->id);
        \Illuminate\Support\Facades\Queue::fake();

        $this->failingPaths = ['/api/game/players/*/suspend'];
        $this->suspensions()->suspend($shopAlice, $this->admin('supervisor'), '停權');

        // 充值端已經立即阻擋，補做工作已排入佇列。
        $this->assertNotNull($shopAlice->fresh()->suspended_at);
        $this->assertNull($alice->fresh()->suspended_at);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ApplyPlayerSuspension::class);

        $this->failingPaths = [];
        (new \App\Jobs\ApplyPlayerSuspension($shopAlice->id))->handle(app(\App\Game\GameGateway::class));
        $this->assertNotNull($alice->fresh()->suspended_at);
    }

    public function test_suspend_route_requires_supervisor_and_totp(): void
    {
        $alice = $this->player('alice');
        $shopAlice = Player::forGame($alice->id);
        $supervisor = $this->admin('supervisor');

        $this->actingAs($this->admin('reviewer'), 'admin')
            ->post(route('admin.players.suspend', $shopAlice), ['reason' => 'x', 'code' => '123456'])->assertForbidden();
        $this->actingAs($supervisor, 'admin')
            ->post(route('admin.players.suspend', $shopAlice), ['reason' => 'x', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($supervisor, 'admin')
            ->post(route('admin.players.suspend', $shopAlice), ['code' => $this->totp($supervisor)])->assertSessionHasErrors('reason');
        $this->assertNull($shopAlice->fresh()->suspended_at);

        $this->actingAs($supervisor, 'admin')
            ->post(route('admin.players.suspend', $shopAlice), ['reason' => '停權', 'code' => $this->totp($supervisor)]);
        $this->assertNotNull($shopAlice->fresh()->suspended_at);
    }

    public function test_debit_api_signature_idempotency_balance_and_suspension(): void
    {
        $alice = $this->player('alice', 20000);
        $this->submitRefund($alice, 15000);

        $call = function (array $data, ?string $secret = null) {
            $path = '/api/points/debit';
            $body = json_encode($data);
            $server = ['CONTENT_TYPE' => 'application/json'];
            foreach (ApiSignature::headers($secret ?? config('topup.game_api_secret'), 'POST', $path, $body) as $name => $value) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }

            return $this->call('POST', $path, [], [], [], $server, $body);
        };
        $form = ['player_no' => $alice->player_no, 'points' => 1000, 'idempotency_key' => 'game-1', 'purpose' => '小遊戲'];

        $call($form, 'wrong')->assertStatus(401);
        $this->assertSame(20000, $this->wallet($alice)[0]);

        $call($form)->assertOk();
        $call($form)->assertOk();
        $this->assertSame([19000, 15000], $this->wallet($alice));

        // 只能扣可用餘額：可用 4,000。
        $call(['idempotency_key' => 'game-2', 'points' => 4001] + $form)->assertStatus(422)->assertJson(['error' => 'insufficient_points']);
        $this->assertSame([19000, 15000], $this->wallet($alice));

        $this->suspensions()->suspend(Player::forGame($alice->id), $this->admin('supervisor'), '停權');
        $call(['idempotency_key' => 'game-3', 'points' => 1] + $form)->assertStatus(403);
        $this->assertLedgerConsistent();
    }

    public function test_debit_idempotency_key_is_scoped_per_player(): void
    {
        $alice = $this->player('alice', 1000);
        $bob = $this->player('bob', 1000);

        foreach ([$alice, $bob] as $player) {
            $path = '/api/points/debit';
            $body = json_encode(['player_no' => $player->player_no, 'points' => 100, 'idempotency_key' => 'same-key', 'purpose' => '小遊戲']);
            $server = ['CONTENT_TYPE' => 'application/json'];
            foreach (ApiSignature::headers(config('topup.game_api_secret'), 'POST', $path, $body) as $name => $value) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }
            $this->call('POST', $path, [], [], [], $server, $body)->assertOk();
        }

        $this->assertSame([900, 0], $this->wallet($alice));
        $this->assertSame([900, 0], $this->wallet($bob));
    }

    public function test_task_points_are_custom_without_business_cap_but_bounded(): void
    {
        $alice = $this->player('alice');

        $this->actingAs($alice, 'game')->post(route('game.task'), ['points' => 1000000000000, 'request_id' => (string) Str::uuid()]);
        $this->assertSame(1000000000000, $this->wallet($alice)[0]);

        foreach ([0, -1, 'abc', '9223372036854775808'] as $bad) {
            $this->actingAs($alice, 'game')->post(route('game.task'), ['points' => $bad, 'request_id' => (string) Str::uuid()])
                ->assertSessionHasErrors('points');
        }

        // 溢位保護。
        $this->actingAs($alice, 'game')->post(route('game.task'), ['points' => PHP_INT_MAX, 'request_id' => (string) Str::uuid()])
            ->assertSessionHasErrors('points');
        $this->assertSame(1000000000000, $this->wallet($alice)[0]);
        $this->assertLedgerConsistent();
    }

    public function test_demo_panel_is_supervisor_only_and_cannot_change_points(): void
    {
        $alice = $this->player('alice', 1000);
        $bob = $this->player('bob');
        $packet = app(RedPacketService::class)->send($alice, $bob->player_no, 300, null, (string) Str::uuid());
        $supervisor = $this->admin('supervisor');

        $this->actingAs($this->admin('reviewer'), 'admin')->get(route('admin.demo'))->assertForbidden();
        $this->actingAs($supervisor, 'admin')->get(route('admin.demo'))->assertOk();

        $this->post(route('admin.demo.next-payout'), ['result' => 'failed']);
        $this->assertSame('failed', Cache::get(MockGateway::NEXT_PAYOUT_CACHE_KEY));

        $this->post(route('admin.demo.expire-packet'), ['packet_id' => $packet->id]);
        $this->assertSame('expired', $packet->fresh()->status);
        $this->assertSame([1000, 0], $this->wallet($alice));

        $refund = $this->submitRefund($this->player('carol', 10000), 10000);
        $this->post(route('admin.demo.overdue-refund'), ['refund_id' => $refund->id]);
        $this->assertTrue($refund->fresh()->isOverdue());

        $this->from(route('admin.demo'))->post(route('admin.demo.reset'), ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        $this->assertSame(3, \App\DemoGame\Models\GamePlayer::count());

        $this->post(route('admin.demo.reset'), ['confirm' => 'RESET']);
        $this->assertSame(0, \App\DemoGame\Models\GamePlayer::count());
        $this->assertSame(0, RefundRequest::count());
        $this->assertTrue($supervisor->exists);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'demo.reset')->count());
    }

    public function test_package_management_is_supervisor_only(): void
    {
        $this->actingAs($this->admin('reviewer'), 'admin')->post(route('admin.packages.store'), ['name' => 'x', 'price' => 10])->assertForbidden();

        $this->actingAs($this->admin('supervisor'), 'admin')->post(route('admin.packages.store'), ['name' => '新方案', 'price' => 200])->assertRedirect();
        $package = \App\Models\Package::where('name', '新方案')->firstOrFail();
        $this->assertSame(20000, $package->points());

        $this->post(route('admin.packages.update', $package), ['name' => '新方案', 'price' => 200]);
        $this->assertFalse($package->fresh()->active);

        $shop = Player::forGame($this->player()->id);
        $this->actingAs($shop, 'shop')->get(route('shop.index'))->assertDontSee('新方案');
    }

    public function test_all_pages_render(): void
    {
        $alice = $this->player('alice', 100000);
        $bob = $this->player('bob');
        $shop = Player::forGame($alice->id);
        $order = $this->order($alice);
        app(RedPacketService::class)->send($alice, $bob->player_no, 10, 'hi', (string) Str::uuid());
        $refund = $this->submitRefund($alice, 10000);
        $this->adjustments()->initiate($shop, $this->admin('reviewer'), 'add', 1, 'x');
        Anomaly::raise('amount_mismatch', 'Order', $order->id, 'x');

        foreach (['game.dashboard', 'game.ledger', 'game.orders', 'game.red-packets', 'game.refunds'] as $route) {
            $this->actingAs($alice->fresh(), 'game')->get(route($route))->assertOk();
        }
        $this->actingAs($alice->fresh(), 'game')->post(route('game.go-shop'))->assertOk()->assertSee('data-autosubmit', false);
        foreach ([route('shop.index'), route('shop.orders.show', $order->order_no), route('mock.pay.show', $order->order_no)] as $url) {
            $this->actingAs($shop, 'shop')->get($url)->assertOk();
        }
        foreach (['reviewer', 'supervisor'] as $role) {
            $this->actingAs($this->admin($role), 'admin');
            foreach ([route('admin.refunds'), route('admin.refunds.show', $refund), route('admin.players'), route('admin.players.show', $shop), route('admin.adjustments'), route('admin.packages'), route('admin.anomalies'), route('admin.audit')] as $url) {
                $this->get($url)->assertOk();
            }
        }
        $this->get(route('admin.demo'))->assertOk();
        $this->get(route('game.login'))->assertOk();
        $this->get(route('game.register'))->assertOk();
        $this->get(route('admin.login'))->assertOk();
    }
}
