<?php

namespace Tests\Feature;

use App\DemoGame\Models\GamePlayer;
use App\Models\Order;
use App\Models\Player;
use App\Payments\MockCheckout;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MockPayTest extends TestCase
{
    private function pay(Order $order, array $form)
    {
        $player = Player::findOrFail($order->player_id);

        return $this->actingAs($player, 'shop')
            ->from(route('mock.pay.show', $order->order_no))
            ->post(route('mock.pay.submit', $order->order_no), $form);
    }

    private function card(array $overrides = []): array
    {
        return $overrides + [
            'method' => 'card',
            'card_name' => 'TEST USER',
            'card_number' => '4111 1111 1111 1111',
            'card_expiry' => now()->addYears(2)->format('m/y'),
            'card_cvv' => '123',
        ];
    }

    private function assertNotPaid(Order $order): void
    {
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, DB::table('gateway_events')->count());
    }

    public function test_card_format_errors_are_rejected_without_sending_a_webhook(): void
    {
        $order = $this->order($this->player());

        $cases = [
            'card_name' => ['card_name' => '王小明'],
            'card_number' => ['card_number' => '4111 1111 1111 1112'],          // 檢查碼錯誤
            'card_expiry' => ['card_expiry' => '13/30'],
            'card_cvv' => ['card_cvv' => '12'],
        ];
        foreach ($cases as $field => $override) {
            $this->pay($order, $this->card($override))
                ->assertRedirect(route('mock.pay.show', $order->order_no))
                ->assertSessionHasErrors($field);
        }

        $this->pay($order, $this->card(['card_expiry' => '01/20']))->assertSessionHasErrors(['card_expiry' => '這張卡已過期']);
        $this->pay($order, $this->card(['card_number' => '6011 1111 1111 1117']))->assertSessionHasErrors(['card_number' => '不支援這個卡別（支援 Visa、Mastercard、JCB、American Express）']);
        $this->pay($order, $this->card(['card_number' => '3782 822463 10005']))->assertSessionHasErrors(['card_cvv' => '這個卡別的安全碼是 4 位數字']);
        $this->assertNotPaid($order);
    }

    public function test_valid_card_that_is_not_a_test_card_is_rejected(): void
    {
        $order = $this->order($this->player());

        // 格式、檢查碼都正確，但不在測試卡清單裡。
        $this->pay($order, $this->card(['card_number' => '4242 4242 4242 4242']))
            ->assertSessionHasErrors(['card_number' => '這是模擬金流，只接受頁面上列出的測試卡號']);
        $this->assertNotPaid($order);
    }

    public function test_card_number_and_cvv_are_not_kept_in_session_or_database(): void
    {
        $player = $this->player();
        $order = $this->order($player);

        $this->pay($order, $this->card(['card_cvv' => '12']))->assertSessionHasErrors('card_cvv');
        $old = session()->getOldInput();
        $this->assertSame('TEST USER', $old['card_name']);
        $this->assertArrayNotHasKey('card_number', $old);
        $this->assertArrayNotHasKey('card_cvv', $old);

        $this->pay($order, $this->card(['card_number' => '378282246310005', 'card_cvv' => '1234']))->assertRedirect(route('shop.orders.show', $order->order_no));
        $this->assertSame('fulfilled', $order->fresh()->status);

        foreach (['gateway_events', 'payments', 'orders', 'audit_logs'] as $table) {
            $dump = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString('378282246310005', $dump, $table);
            $this->assertStringNotContainsString('TEST USER', $dump, $table);
        }
        $this->assertLedgerConsistent();
    }

    public function test_atm_transfer_must_match_bank_account_and_amount(): void
    {
        $player = $this->player();
        $order = $this->order($player, 500);
        $account = MockCheckout::virtualAccount($order);

        $this->assertMatchesRegularExpression('/^\d{14}$/', $account);
        $this->assertTrue(MockCheckout::luhn($account));
        $this->assertSame($account, MockCheckout::virtualAccount($order->fresh()));
        $this->assertNotSame($account, MockCheckout::virtualAccount($this->order($player, 500)));

        $form = ['method' => 'atm', 'atm_bank' => '999', 'atm_account' => $account, 'atm_amount' => '500'];
        $this->pay($order, ['atm_bank' => '812'] + $form)->assertSessionHasErrors(['atm_bank' => '轉入銀行代碼不正確']);
        $this->pay($order, ['atm_account' => '12345678901234'] + $form)->assertSessionHasErrors(['atm_account' => '轉入帳號與這筆訂單的虛擬帳號不符']);
        $this->pay($order, ['atm_amount' => '499'] + $form)->assertSessionHasErrors(['atm_amount' => '轉帳金額必須等於應繳金額 500 元']);
        $this->pay($order, ['atm_amount' => '5e2'] + $form)->assertSessionHasErrors(['atm_amount' => '轉帳金額必須是整數']);
        $this->assertNotPaid($order);

        $this->pay($order, ['atm_account' => chunk_split($account, 4, ' ')] + $form)->assertRedirect(route('shop.orders.show', $order->order_no));
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame([50000, 0], $this->wallet($player));
    }

    public function test_store_code_payment_and_limit(): void
    {
        $player = $this->player();
        $order = $this->order($player, 300);
        $code = MockCheckout::storeCode($order);
        $this->assertMatchesRegularExpression('/^DM[2-9A-HJ-NP-Z]{12}$/', $code);

        $form = ['method' => 'cvs', 'cvs_code' => strtolower($code), 'cvs_amount' => '300'];
        $this->pay($order, ['cvs_code' => 'DM000000000000'] + $form)->assertSessionHasErrors(['cvs_code' => '繳費代碼與這筆訂單不符']);
        $this->pay($order, ['cvs_code' => 'DM12'] + $form)->assertSessionHasErrors(['cvs_code' => '繳費代碼必須是 14 個英文字母或數字']);
        $this->assertNotPaid($order);

        $this->pay($order, $form)->assertRedirect(route('shop.orders.show', $order->order_no));
        $this->assertSame('fulfilled', $order->fresh()->status);

        $large = $this->order($player, MockCheckout::CVS_LIMIT + 1);
        $this->actingAs(Player::findOrFail($large->player_id), 'shop')->get(route('mock.pay.show', $large->order_no))->assertSee('這筆訂單請改用其他付款方式');
        $this->pay($large, ['method' => 'cvs', 'cvs_code' => MockCheckout::storeCode($large), 'cvs_amount' => (string) $large->amount])
            ->assertSessionHasErrors('method');
        $this->assertSame('pending', $large->fresh()->status);
    }

    public function test_only_pending_orders_within_deadline_can_be_paid(): void
    {
        $player = $this->player();
        $order = $this->order($player);
        $this->pay($order, $this->card())->assertRedirect(route('shop.orders.show', $order->order_no));
        $this->assertSame([50000, 0], $this->wallet($player));

        $this->pay($order, $this->card())->assertSessionHasErrors(['method' => '這筆訂單已不是待付款狀態，無法付款']);
        $this->actingAs(Player::findOrFail($order->player_id), 'shop')->get(route('mock.pay.show', $order->order_no))
            ->assertSee('不能再付款')->assertDontSee('確認付款');

        $late = $this->order($player);
        $late->update(['expires_at' => now()->subMinute()]);
        $this->pay($late, $this->card())->assertSessionHasErrors('method');
        $this->assertSame('pending', $late->fresh()->status);
        $this->assertSame([50000, 0], $this->wallet($player));
        $this->assertLedgerConsistent();
    }

    public function test_completed_payment_returns_to_player_center_once(): void
    {
        $order = $this->order($this->player());
        $shopPlayer = Player::findOrFail($order->player_id);
        $this->pay($order, $this->card())->assertRedirect(route('shop.orders.show', $order->order_no));

        $this->actingAs($shopPlayer, 'shop')->get(route('shop.orders.show', $order->order_no))
            ->assertRedirect(route('game.dashboard'))
            ->assertSessionHas('status', "充值完成，已入帳 50,000 點（訂單 {$order->order_no}）");
        // 之後再開訂單頁就照常顯示。
        $this->actingAs($shopPlayer, 'shop')->get(route('shop.orders.show', $order->order_no))
            ->assertOk()->assertSee('已完成')->assertSee('回玩家中心');

        // 發卡行拒絕：留在訂單頁，照實說明原因並提供重新下單。
        $failed = $this->order(GamePlayer::findOrFail($shopPlayer->game_player_id));
        $this->pay($failed, $this->card(['card_number' => '4000000000000002']));
        $this->assertSame(['failed', 'card_declined'], [$failed->fresh()->status, $failed->fresh()->failure_code]);
        $this->actingAs($shopPlayer, 'shop')->get(route('shop.orders.show', $failed->order_no))
            ->assertOk()->assertSee('發卡行拒絕交易')->assertSee('重新選擇方案')->assertDontSee('充值失敗，請聯繫客服');
        $this->assertSame('付款失敗（發卡行拒絕）', $failed->fresh()->statusLabel());

        // 沒有原因代碼的付款失敗仍只顯示固定訊息；不認得的代碼不會被記下。
        $other = $this->order(GamePlayer::findOrFail($shopPlayer->game_player_id));
        $this->sendWebhook($this->paymentEvent($other, ['type' => 'payment.failed', 'failure_code' => 'stolen_card']))->assertOk();
        $this->assertSame(['failed', null], [$other->fresh()->status, $other->fresh()->failure_code]);
        $this->actingAs($shopPlayer, 'shop')->get(route('shop.orders.show', $other->order_no))
            ->assertSee('充值失敗，請聯繫客服')->assertDontSee('發卡行拒絕交易');

        // 沒有經過付款頁的已完成訂單不跳轉。
        $direct = $this->order(GamePlayer::findOrFail($shopPlayer->game_player_id));
        $this->sendWebhook($this->paymentEvent($direct))->assertOk();
        $this->actingAs($shopPlayer, 'shop')->get(route('shop.orders.show', $direct->order_no))->assertOk();
    }

    public function test_topup_page_lists_packages_and_carries_payment_method(): void
    {
        $player = Player::forGame($this->player()->id);
        $package = $this->package(300);

        $this->actingAs($player, 'shop')->get(route('shop.index'))
            ->assertOk()->assertSee('立即儲值')->assertSee('NT$300')->assertSee('30,000')
            ->assertSee('name="method" value="atm"', false);

        $response = $this->actingAs($player, 'shop')->post(route('shop.orders.create'), ['package_id' => $package->id, 'method' => 'atm']);
        $order = Order::sole();
        $response->assertRedirect(route('mock.pay.show', $order->order_no).'?method=atm');
        $this->actingAs($player, 'shop')->get(route('mock.pay.show', [$order->order_no, 'method' => 'atm']))
            ->assertSee('id="tab-atm" checked', false);
        // 付款方式只決定預設分頁，不會改變訂單金額。
        $this->assertSame([300, 30000], [$order->amount, $order->points]);

        $this->actingAs($player, 'shop')->post(route('shop.orders.create'), ['package_id' => $package->id, 'method' => 'bitcoin'])->assertSessionHasErrors('method');
        $this->actingAs($player, 'shop')->post(route('shop.orders.create'), [])->assertSessionHasErrors(['package_id' => '請選擇儲值方案']);
        $this->assertSame(1, Order::count());
    }

    public function test_unknown_method_or_scenario_is_rejected(): void
    {
        $order = $this->order($this->player());

        $this->pay($order, ['method' => 'crypto'])->assertSessionHasErrors(['method' => '請選擇付款方式']);
        $this->pay($order, ['scenario' => 'success'])->assertSessionHasErrors('method');      // 舊的情境參數無效
        $this->pay($order, ['method' => 'advanced', 'scenario' => 'success'])->assertSessionHasErrors('scenario');
        $this->assertNotPaid($order);
    }

    public function test_page_lists_test_cards_and_payment_codes(): void
    {
        $order = $this->order($this->player(), 500);

        $this->actingAs(Player::findOrFail($order->player_id), 'shop')->get(route('mock.pay.show', $order->order_no))
            ->assertOk()
            ->assertSee('4111 1111 1111 1111')
            ->assertSee('3782 822463 10005')
            ->assertSee(trim(chunk_split(MockCheckout::virtualAccount($order), 4, ' ')))
            ->assertSee(MockCheckout::storeCode($order))
            ->assertSee('autocomplete="off"', false);
    }
}
