<?php

namespace Tests\Feature;

use App\Jobs\GrantPoints;
use App\Models\Anomaly;
use App\Models\Order;
use App\Models\Package;
use App\Models\PointGrant;
use App\Payments\MockGateway;
use App\Services\OrderService;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    public function test_successful_payment_grants_points_at_fixed_rate(): void
    {
        $player = $this->player();
        $order = $this->order($player, 500);

        $this->sendWebhook($this->paymentEvent($order))->assertOk();

        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame([50000, 0], $this->wallet($player));
        $this->assertLedgerConsistent();
    }

    public function test_duplicate_webhook_grants_once(): void
    {
        $player = $this->player();
        $order = $this->order($player);
        $event = $this->paymentEvent($order);

        $this->sendWebhook($event)->assertOk();
        $this->sendWebhook($event)->assertOk();
        // 不同事件、不同交易編號也不會重複入點。
        $this->sendWebhook($this->paymentEvent($order))->assertOk();

        $this->assertSame([50000, 0], $this->wallet($player));
        $this->assertSame(1, PointGrant::count());
        $this->assertLedgerConsistent();
    }

    public function test_bad_signature_is_rejected(): void
    {
        $player = $this->player();
        $order = $this->order($player);

        $this->sendWebhook($this->paymentEvent($order), str_repeat('0', 64))->assertStatus(401);

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame([0, 0], $this->wallet($player));
    }

    public function test_amount_mismatch_does_not_grant_and_raises_anomaly(): void
    {
        $player = $this->player();
        $order = $this->order($player);

        $this->sendWebhook($this->paymentEvent($order, ['amount' => $order->amount + 1]))->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame([0, 0], $this->wallet($player));
        $this->assertSame(1, Anomaly::where('kind', 'amount_mismatch')->whereNull('resolved_at')->count());
    }

    public function test_payment_after_expiry_still_grants(): void
    {
        $player = $this->player();
        $order = $this->order($player);
        Order::whereKey($order->id)->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, app(OrderService::class)->expireDue());
        $this->assertSame('expired', $order->fresh()->status);

        $this->sendWebhook($this->paymentEvent($order))->assertOk();

        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame([50000, 0], $this->wallet($player));
    }

    public function test_grant_failure_can_be_retried_without_double_grant(): void
    {
        $player = $this->player();
        $order = $this->order($player);

        $grant = PointGrant::create(['order_id' => $order->id, 'player_id' => $order->player_id, 'points' => $order->points]);

        // 第一次：玩家端故障。
        $this->failingPaths = ['/api/game/players/*/grant'];
        try {
            (new GrantPoints($grant->id))->handle(app(\App\Services\FulfillmentService::class));
            $this->fail('expected the grant to fail');
        } catch (\Illuminate\Http\Client\RequestException) {
        }
        $this->assertSame('pending', $grant->fresh()->status);

        // 用盡重試。
        (new GrantPoints($grant->id))->failed(new \RuntimeException('outage'));
        $this->assertSame('failed', $grant->fresh()->status);
        $this->assertSame(1, Anomaly::where('kind', 'grant_failed')->whereNull('resolved_at')->count());

        // 恢復後，由覆核者重新入點；再跑一次也不會重複。
        $this->failingPaths = [];
        app(\App\Services\FulfillmentService::class)->retry($grant->fresh(), $this->admin('supervisor'));
        (new GrantPoints($grant->id))->handle(app(\App\Services\FulfillmentService::class));

        $this->assertSame('granted', $grant->fresh()->status);
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame([50000, 0], $this->wallet($player));
        $this->assertSame(0, Anomaly::whereNull('resolved_at')->count());
        $this->assertLedgerConsistent();
    }

    public function test_order_snapshot_is_not_affected_by_package_changes(): void
    {
        $player = $this->player();
        $order = $this->order($player, 500);

        Package::whereKey($order->package_id)->update(['price' => 9999, 'name' => '改過的方案']);
        $this->sendWebhook($this->paymentEvent($order))->assertOk();

        $this->assertSame(500, $order->fresh()->amount);
        $this->assertSame([50000, 0], $this->wallet($player));
    }

    public function test_inactive_package_cannot_be_ordered(): void
    {
        $package = $this->package(100);
        $package->update(['active' => false]);

        $this->expectException(\RuntimeException::class);
        app(OrderService::class)->create(Player::forGame($this->player()->id), $package);
    }

    public function test_mock_pay_page_delivers_webhook_for_each_scenario(): void
    {
        $player = $this->player();
        $shopPlayer = Player::forGame($player->id);

        $cases = ['success' => 'fulfilled', 'duplicate' => 'fulfilled', 'failed' => 'failed', 'bad_signature' => 'pending', 'amount_mismatch' => 'pending'];
        foreach ($cases as $scenario => $status) {
            $before = $this->wallet($player)[0];
            $order = $this->order($player, 100);

            $this->actingAs($shopPlayer, 'shop')
                ->post(route('mock.pay.submit', $order->order_no), ['scenario' => $scenario])
                ->assertRedirect();

            $this->assertSame($status, $order->fresh()->status, $scenario);
            $this->assertSame($before + ($status === 'fulfilled' ? 10000 : 0), $this->wallet($player)[0], $scenario);
        }
        $this->assertLedgerConsistent();
    }

    public function test_other_players_order_is_not_visible(): void
    {
        $order = $this->order($this->player('alice'));
        $bob = Player::forGame($this->player('bob')->id);

        $this->actingAs($bob, 'shop')->get(route('shop.orders.show', $order->order_no))->assertNotFound();
        $this->actingAs($bob, 'shop')->get(route('mock.pay.show', $order->order_no))->assertNotFound();
    }

    public function test_price_comes_from_server_not_from_client(): void
    {
        $package = $this->package(1000);
        $player = Player::forGame($this->player()->id);

        $this->actingAs($player, 'shop')
            ->post(route('shop.orders.create'), ['package_id' => $package->id, 'amount' => 1, 'points' => 999999])
            ->assertRedirect();

        $order = Order::firstOrFail();
        $this->assertSame(1000, $order->amount);
        $this->assertSame(100000, $order->points);
    }

    public function test_events_are_recorded_once(): void
    {
        $order = $this->order($this->player());
        $event = $this->paymentEvent($order);
        $this->sendWebhook($event);
        $this->sendWebhook($event);

        $this->assertSame(1, DB::table('gateway_events')->where('event_id', $event['event_id'])->count());
    }

    public function test_mock_scenarios_are_exposed(): void
    {
        $this->assertArrayHasKey('delayed', MockGateway::PAYMENT_SCENARIOS);
    }
}
