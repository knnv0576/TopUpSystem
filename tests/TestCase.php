<?php

namespace Tests;

use App\DemoGame\Models\GamePlayer;
use App\DemoGame\Services\WalletService;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Package;
use App\Models\Player;
use App\Models\RefundRequest;
use App\Payments\MockGateway;
use App\Services\OrderService;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** 回傳 500 的內部路徑（用來模擬對方服務故障）。 */
    protected array $failingPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 測試資料庫才可以執行；避免誤清開發資料。
        $this->assertSame('topup_test', DB::connection()->getDatabaseName());

        // 把內部 HTTP 呼叫轉回同一個應用程式，讓簽章、路由與控制器都實際跑過。
        Http::fake(function (ClientRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            foreach ($this->failingPaths as $failing) {
                if (Str::is($failing, $path)) {
                    return Http::response(['error' => 'simulated_outage'], 500);
                }
            }

            $server = [];
            foreach ($request->headers() as $name => $values) {
                $key = strtoupper(str_replace('-', '_', $name));
                $server[$key === 'CONTENT_TYPE' ? $key : "HTTP_$key"] = $values[0];
            }

            $response = $this->call($request->method(), $path, [], [], [], $server, $request->body());

            return Http::response($response->getContent(), $response->getStatusCode());
        });
    }

    protected function player(string $name = 'alice', int $points = 0): GamePlayer
    {
        $player = GamePlayer::register($name, 'Password123456', $name);
        if ($points > 0) {
            app(WalletService::class)->credit($player->id, $points, 'task', "seed:{$player->id}", '測試');
        }

        return $player->fresh();
    }

    protected function admin(string $role): AdminUser
    {
        return AdminUser::firstOrCreate(['username' => $role], [
            'password' => 'Password123456',
            'role' => $role,
            'totp_secret' => (new Google2FA)->generateSecretKey(32),
            'totp_confirmed_at' => now(),
        ]);
    }

    /** 產生可用的 TOTP；清除上次使用紀錄，測試才不用等下一個時間步。 */
    protected function totp(AdminUser $admin): string
    {
        AdminUser::whereKey($admin->id)->update(['totp_last_timestamp' => null]);

        return (new Google2FA)->getCurrentOtp($admin->fresh()->totp_secret);
    }

    protected function package(int $price = 500): Package
    {
        return Package::create(['name' => "$price 元", 'price' => $price, 'active' => true]);
    }

    protected function order(GamePlayer $player, int $price = 500): Order
    {
        return app(OrderService::class)->create(Player::forGame($player->id), $this->package($price));
    }

    protected function paymentEvent(Order $order, array $overrides = []): array
    {
        return $overrides + [
            'event_id' => (string) Str::uuid(),
            'type' => 'payment.succeeded',
            'order_no' => $order->order_no,
            'provider_txn_id' => 'TXN'.Str::random(10),
            'amount' => $order->amount,
            'currency' => 'TWD',
        ];
    }

    protected function sendWebhook(array $payload, ?string $signature = null)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/payments/mock', [], [], [], [
            'HTTP_X_MOCK_SIGNATURE' => $signature ?? MockGateway::sign($body),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /** 送出申請；同步佇列會完成凍結，回傳 pending_review 的申請。 */
    protected function submitRefund(GamePlayer $player, int $points = 10000): RefundRequest
    {
        return app(RefundService::class)
            ->submit((string) Str::uuid(), $player->id, $points, '812', '1234567890123')
            ->fresh();
    }

    /** 幫玩家綁定一個撥款帳戶（直接呼叫充值端服務）。 */
    protected function bankAccount(GamePlayer $player, string $bankCode = '812', string $account = '1234567890', string $label = '測試帳戶', string $holder = '王小明'): \App\Models\PlayerBankAccount
    {
        return app(\App\Services\BankAccountService::class)->add(Player::forGame($player->id), $label, $bankCode, $account, $holder);
    }

    protected function wallet(GamePlayer $player): array
    {
        $wallet = $player->wallet()->first();

        return [(int) $wallet->balance, (int) $wallet->frozen];
    }

    /** 帳本加總要等於錢包餘額。 */
    protected function assertLedgerConsistent(): void
    {
        foreach (GamePlayer::with('wallet')->get() as $player) {
            $sum = DB::table('game_point_ledger')->where('player_id', $player->id)
                ->selectRaw('COALESCE(SUM(delta_balance),0) AS b, COALESCE(SUM(delta_frozen),0) AS f')->first();
            $this->assertSame((int) $sum->b, (int) $player->wallet->balance, "player {$player->id} balance");
            $this->assertSame((int) $sum->f, (int) $player->wallet->frozen, "player {$player->id} frozen");
        }
    }
}
