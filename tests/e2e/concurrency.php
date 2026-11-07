<?php

// 併發驗證：對實際服務（nginx + php-fpm + PostgreSQL）同時送出相同請求，再核對資料庫。
// 用法：php tests/e2e/concurrency.php   （在 app 容器內執行；會建立 c_ 開頭的測試玩家）

use App\DemoGame\Models\GamePlayer;
use App\DemoGame\Models\RedPacket;
use App\DemoGame\Services\RedPacketService;
use App\DemoGame\Services\WalletService;
use App\MiniGame\Models\WheelRound;
use App\MiniGame\Services\ReconciliationService;
use App\MiniGame\Services\WheelService;
use App\MiniGame\Support\WheelOutcome;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Player;
use App\Models\PointGrant;
use App\Models\RefundRequest;
use App\Payments\MockGateway;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Support\ApiSignature;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const BASE = 'http://web';
const PARALLEL = 20;

/** @return int[] HTTP 狀態碼 */
function fire(array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as [$path, $headers, $body]) {
        $handle = curl_init(BASE.$path);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.2);
    } while ($running);

    return array_map(fn ($h) => curl_getinfo($h, CURLINFO_RESPONSE_CODE), $handles);
}

function check(string $name, bool $ok, string $detail = ''): void
{
    echo($ok ? 'PASS' : 'FAIL')."  $name $detail\n";
    if (! $ok) {
        $GLOBALS['failed'] = true;
    }
}

function newPlayer(string $prefix, int $points): GamePlayer
{
    $player = GamePlayer::register("c_{$prefix}_".Str::lower(Str::random(6)), 'Password123456', $prefix);
    if ($points > 0) {
        app(WalletService::class)->credit($player->id, $points, 'task', "c-seed:{$player->id}", '併發測試');
    }

    return $player;
}

function waitFor(callable $condition, int $seconds = 30): bool
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        if ($condition()) {
            return true;
        }
        usleep(200000);
    }

    return false;
}

// 1. 相同的付款 Webhook：同一事件與不同事件各半。
$payer = newPlayer('payer', 0);
$package = Package::where('active', true)->orderBy('price')->firstOrFail();
$order = app(OrderService::class)->create(Player::forGame($payer->id), $package);
$sameEvent = json_encode(['event_id' => (string) Str::uuid(), 'type' => 'payment.succeeded', 'order_no' => $order->order_no, 'provider_txn_id' => 'CTX1', 'amount' => $order->amount, 'currency' => 'TWD']);
$requests = [];
for ($i = 0; $i < PARALLEL; $i++) {
    $body = $i % 2 ? $sameEvent : json_encode(['event_id' => (string) Str::uuid(), 'type' => 'payment.succeeded', 'order_no' => $order->order_no, 'provider_txn_id' => "CTX-$i", 'amount' => $order->amount, 'currency' => 'TWD']);
    $requests[] = ['/webhooks/payments/mock', ['Content-Type' => 'application/json', 'X-Mock-Signature' => MockGateway::sign($body)], $body];
}
$codes = fire($requests);
check('webhook 全部回應 200', count(array_filter($codes, fn ($c) => $c === 200)) === PARALLEL, json_encode(array_count_values($codes)));
waitFor(fn () => PointGrant::where('order_id', $order->id)->where('status', 'granted')->exists());
check('webhook 只入點一次', (int) $payer->wallet()->first()->balance === $order->points, 'balance='.$payer->wallet()->first()->balance.' expected='.$order->points);
check('webhook 只有一筆 grant', PointGrant::where('order_id', $order->id)->count() === 1);
check('webhook 帳本只有一筆入點', DB::table('game_point_ledger')->where('player_id', $payer->id)->where('type', 'topup')->count() === 1);

// 2. 相同冪等鍵的扣點，以及搶同一筆餘額的不同扣點。
$debit = newPlayer('debit', 1000);
$signed = function (array $data): array {
    $path = '/api/points/debit';
    $body = json_encode($data);

    return [$path, ApiSignature::headers(config('topup.game_api_secret'), 'POST', $path, $body), $body];
};
$codes = fire(array_map(fn () => $signed(['player_no' => $debit->player_no, 'points' => 100, 'idempotency_key' => 'same', 'purpose' => '併發']), range(1, PARALLEL)));
check('相同冪等鍵全部成功', count(array_filter($codes, fn ($c) => $c === 200)) === PARALLEL, json_encode(array_count_values($codes)));
check('相同冪等鍵只扣一次', (int) $debit->wallet()->first()->balance === 900);

$codes = fire(array_map(fn ($i) => $signed(['player_no' => $debit->player_no, 'points' => 100, 'idempotency_key' => "k$i", 'purpose' => '併發']), range(1, PARALLEL)));
$counts = array_count_values($codes);
check('不同冪等鍵不會超扣', (int) $debit->wallet()->first()->balance === 0, 'balance='.$debit->wallet()->first()->balance.' '.json_encode($counts));
check('恰好 9 筆成功', ($counts[200] ?? 0) === 9 && ($counts[422] ?? 0) === PARALLEL - 9);

// 3. 紅包同時領取。領取是網頁操作，這裡以多個程序直接呼叫服務。
$sender = newPlayer('sender', 1000);
$recipient = newPlayer('recipient', 0);
$packet = app(RedPacketService::class)->send($sender, $recipient->player_no, 300, null, (string) Str::uuid());
$php = PHP_BINARY;
$script = sprintf(
    'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); try { $a->make(App\DemoGame\Services\RedPacketService::class)->claim(App\DemoGame\Models\GamePlayer::find(%d), %d); echo "ok"; } catch (Throwable $e) { echo "no"; }',
    $recipient->id,
    $packet->id,
);
$processes = [];
for ($i = 0; $i < 10; $i++) {
    $pipes = [];
    $processes[] = [proc_open([$php, '-r', $script], [1 => ['pipe', 'w']], $pipes), $pipes[1]];
}
$outcomes = [];
foreach ($processes as [$process, $stdout]) {
    $outcomes[] = stream_get_contents($stdout);
    proc_close($process);
}
$counts = array_count_values($outcomes);
check('紅包只有一個程序領取成功', ($counts['ok'] ?? 0) === 1, json_encode($counts));
check('紅包只入帳一次', (int) $recipient->wallet()->first()->balance === 300);
check('紅包狀態正確', RedPacket::find($packet->id)->status === 'claimed');

// 以多個程序同時執行同一段 PHP，回傳每個程序的輸出。
$runAll = function (array $scripts) use ($php): array {
    $bootstrap = 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); ';
    $running = [];
    foreach ($scripts as $code) {
        $pipes = [];
        $running[] = [proc_open([$php, '-r', $bootstrap.$code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes[1], $pipes[2]];
    }
    $outputs = [];
    foreach ($running as [$process, $stdout, $stderr]) {
        $outputs[] = trim(stream_get_contents($stdout));
        stream_get_contents($stderr);
        proc_close($process);
    }

    return $outputs;
};

$pendingSupervisor = function (GamePlayer $player): RefundRequest {
    $refund = app(RefundService::class)->submit((string) Str::uuid(), $player->id, 10000, '812', '1234567890');
    if (! waitFor(fn () => $refund->fresh()->status === 'pending_review')) {
        throw new RuntimeException('凍結工作沒有完成，請確認 queue 服務正在執行');
    }
    app(RefundService::class)->reviewApprove($refund->fresh(), AdminUser::where('role', 'reviewer')->firstOrFail());

    return $refund->fresh();
};
$supervisorId = AdminUser::where('role', 'supervisor')->value('id');
$approveCode = fn (int $id) => sprintf(
    'try { $a->make(App\Services\RefundService::class)->supervisorApprove(App\Models\RefundRequest::find(%d), App\Models\AdminUser::find(%d)); echo "ok"; } catch (RuntimeException $e) { echo "no"; }',
    $id,
    $supervisorId,
);

// 4. 同時覆核核准：只能有一次成功，也只能付款一次。
$approver = newPlayer('approver', 10000);
$refund = $pendingSupervisor($approver);
$counts = array_count_values($runAll(array_fill(0, 10, $approveCode($refund->id))));
check('同時核准只有一次成功', ($counts['ok'] ?? 0) === 1, json_encode($counts));
check('同時核准最後完成撥款', waitFor(fn () => $refund->fresh()->status === 'paid_out'), $refund->fresh()->status);
$payout = DB::table('mock_payouts')->where('idempotency_key', "refund-{$refund->id}")->get();
check('同時核准只付款一次', $payout->count() === 1 && (int) $payout[0]->attempts === 1 && (int) $payout[0]->amount === 100, json_encode($payout));
check('同時核准只扣點一次', (int) $approver->wallet()->first()->balance === 0 && (int) $approver->wallet()->first()->frozen === 0);

// 5. 停權與核准同時發生：不論誰先，帳務都要一致。重複數輪以提高碰撞機率。
$outcomes = [];
for ($round = 0; $round < 6; $round++) {
    $racer = newPlayer('racer', 10000);
    $refund = $pendingSupervisor($racer);
    $shopPlayer = Player::forGame($racer->id);
    $runAll([
        // 兩邊輪流延後啟動，讓「核准先」與「停權先」都實際碰撞到。
        sprintf('usleep(%d); ', $round % 2 ? 0 : $round * 15000).$approveCode($refund->id),
        sprintf('usleep(%d); $a->make(App\Services\PlayerSuspensionService::class)->suspend(App\Models\Player::find(%d), App\Models\AdminUser::find(%d), "併發測試"); echo "suspended";', $round % 2 ? 30000 + $round * 15000 : 0, $shopPlayer->id, $supervisorId),
    ]);

    waitFor(fn () => in_array($refund->fresh()->status, ['paid_out', 'rejected'], true)
        && ($refund->fresh()->status === 'paid_out' || (int) $racer->wallet()->first()->frozen === 0));
    $status = $refund->fresh()->status;
    $outcomes[] = $status;
    $wallet = $racer->wallet()->first();
    $payouts = DB::table('mock_payouts')->where('idempotency_key', "refund-{$refund->id}")->count();

    $consistent = match ($status) {
        'paid_out' => (int) $wallet->balance === 0 && (int) $wallet->frozen === 0 && $payouts === 1,
        'rejected' => (int) $wallet->balance === 10000 && (int) $wallet->frozen === 0 && $payouts === 0,
        default => false,
    };
    check("停權與核准競爭第 $round 輪帳務一致", $consistent, "$status balance={$wallet->balance} frozen={$wallet->frozen} payouts=$payouts");
    check("停權與核准競爭第 $round 輪玩家已停權", $shopPlayer->fresh()->isSuspended() && $racer->fresh()->isSuspended());
}
echo '      競爭結果：'.json_encode(array_count_values($outcomes))."\n";

// 6. 轉盤：同一局同時送出。轉動是網頁操作，這裡以多個程序直接呼叫服務。
$seedForPayout = function (WheelRound $round, int $multiplierPct): string {
    for ($i = 0; ; $i++) {
        $seed = "concurrency-$i";
        if (WheelOutcome::segmentForRoll(WheelOutcome::roll($round->server_seed, $seed, $round->public_id))['multiplier_pct'] === $multiplierPct) {
            return $seed;
        }
    }
};

$spinner = newPlayer('spinner', 1000);
$counts = array_count_values($runAll(array_fill(0, 10, sprintf(
    '$r = $a->make(App\MiniGame\Services\WheelService::class)->ensureCommitted(App\DemoGame\Models\GamePlayer::find(%d)); echo $r->public_id;',
    $spinner->id,
))));
check('同時開啟頁面只有一筆待用的局', count($counts) === 1 && WheelRound::where('game_player_id', $spinner->id)->where('status', 'committed')->count() === 1, json_encode($counts));

$round = app(WheelService::class)->ensureCommitted($spinner);
$seed = $seedForPayout($round, 500);
$runAll(array_fill(0, 10, sprintf(
    'try { $a->make(App\MiniGame\Services\WheelService::class)->spin(App\DemoGame\Models\GamePlayer::find(%d), "%s", 100, "%s"); echo "ok"; } catch (Throwable $e) { echo get_class($e); }',
    $spinner->id,
    $round->public_id,
    $seed,
)));
$ledgerOf = fn (GamePlayer $player, string $type) => DB::table('game_point_ledger')->where('player_id', $player->id)->where('type', $type)->count();
check('同一局同時轉動完成結算', $round->fresh()->status === 'settled', $round->fresh()->status);
check('同一局同時轉動只扣一次', $ledgerOf($spinner, 'game_debit') === 1);
check('同一局同時轉動只發獎一次', $ledgerOf($spinner, 'game_credit') === 1);
check('同一局同時轉動金額正確', (int) $spinner->wallet()->first()->balance === 1400, 'balance='.$spinner->wallet()->first()->balance);

// 7. 同一筆下注同時發獎：走實際的 HTTP。
$winner = newPlayer('winner', 1000);
$round = app(WheelService::class)->ensureCommitted($winner);
[$round] = app(WheelService::class)->begin($winner, $round->public_id, 100, $seedForPayout($round, 1000));
$signedPoints = function (string $path, array $data): array {
    $body = json_encode($data);

    return [$path, ApiSignature::headers(config('topup.game_api_secret'), 'POST', $path, $body), $body];
};
[$path, $headers, $body] = $signedPoints('/api/points/debit', ['player_no' => $winner->player_no, 'points' => 100, 'idempotency_key' => $round->debitKey(), 'purpose' => '併發']);
check('下注成功', fire([[$path, $headers, $body]]) === [200]);
$codes = fire(array_map(fn () => $signedPoints('/api/points/credit', ['player_no' => $winner->player_no, 'points' => 1000, 'debit_idempotency_key' => $round->debitKey(), 'purpose' => '併發']), range(1, PARALLEL)));
check('同時發獎全部成功', count(array_filter($codes, fn ($c) => $c === 200)) === PARALLEL, json_encode(array_count_values($codes)));
check('同時發獎只入帳一次', $ledgerOf($winner, 'game_credit') === 1 && (int) $winner->wallet()->first()->balance === 1900, 'balance='.$winner->wallet()->first()->balance);
$conflict = array_map(fn ($points) => $signedPoints('/api/points/credit', ['player_no' => $winner->player_no, 'points' => $points, 'debit_idempotency_key' => $round->debitKey(), 'purpose' => '併發']), [999, 998, 997, 996, 995]);
$codes = fire($conflict);
check('同一筆下注換金額被拒', array_unique($codes) === [409], json_encode(array_count_values($codes)));
check('換金額沒有改變餘額', (int) $winner->wallet()->first()->balance === 1900);
app(WheelService::class)->advance($round);
check('發獎後局可以結算', $round->fresh()->status === 'settled', $round->fresh()->status);

// 7b. 作廢與扣點同時送出：每一輪只能有一個生效，晚到的另一個不能改變結果。
$voider = newPlayer('voider', 100000);
$outcomes = [];
for ($race = 1; $race <= 10; $race++) {
    $key = "void-race-$race";
    $debitReq = $signedPoints('/api/points/debit', ['player_no' => $voider->player_no, 'points' => 100, 'idempotency_key' => $key, 'purpose' => '併發']);
    $voidReq = $signedPoints('/api/points/debit/void', ['player_no' => $voider->player_no, 'points' => 100, 'idempotency_key' => $key]);
    $requests = [];
    for ($i = 0; $i < 10; $i++) {
        $requests[] = $i % 2 ? $voidReq : $debitReq;
        $requests[] = $i % 2 ? $debitReq : $voidReq;
    }
    fire($requests);

    $ledgerKey = "debit:{$voider->id}:$key";
    $debited = DB::table('game_point_ledger')->where('idempotency_key', $ledgerKey)->count();
    $voided = DB::table('game_point_ledger')->where('idempotency_key', "void:$ledgerKey")->count();
    $outcomes[] = $debited ? 'debited' : 'voided';
    check("作廢與扣點競爭第 $race 輪只有一個生效", $debited + $voided === 1, "debit=$debited void=$voided");

    // 競爭結束後重新簽章再送一次（同一組 nonce 會被防重放擋下），結果必須與帳本一致。
    $retry = $signedPoints('/api/points/debit', ['player_no' => $voider->player_no, 'points' => 100, 'idempotency_key' => $key, 'purpose' => '併發']);
    check("作廢與扣點競爭第 $race 輪重送扣點結果一致", fire([$retry]) === [$debited ? 200 : 409]);
}
$expected = 100000 - 100 * count(array_filter($outcomes, fn ($o) => $o === 'debited'));
check('作廢與扣點競爭後餘額正確', (int) $voider->wallet()->first()->balance === $expected, 'balance='.$voider->wallet()->first()->balance);
echo '      競爭結果：'.json_encode(array_count_values($outcomes))."\n";

// 8. 遊戲內充值：相同 request_id 同時送出。
$buyer = newPlayer('buyer', 0);
$shopBuyer = Player::forGame($buyer->id);
$requestId = (string) Str::uuid();
$topupPath = "/api/topup/players/{$buyer->id}/game-orders";
$codes = fire(array_map(function () use ($topupPath, $requestId, $package) {
    $body = json_encode(['request_id' => $requestId, 'package_id' => $package->id, 'method' => 'card']);

    return [$topupPath, ApiSignature::headers(config('topup.topup_api_secret'), 'POST', $topupPath, $body), $body];
}, range(1, PARALLEL)));
check('相同 request_id 全部回應成功', count(array_filter($codes, fn ($c) => $c === 201)) === PARALLEL, json_encode(array_count_values($codes)));
$orders = Order::where('player_id', $shopBuyer->id)->get();
check('相同 request_id 只有一張訂單', $orders->count() === 1, 'count='.$orders->count());

// 同一張訂單同時送出付款：只有一個會送出。
$payPath = "$topupPath/{$orders[0]->order_no}/pay";
$codes = fire(array_map(function () use ($payPath) {
    $body = json_encode(['card_name' => 'TEST USER', 'card_number' => '4111111111111111', 'card_expiry' => now()->addYear()->format('m/y'), 'card_cvv' => '123']);

    return [$payPath, ApiSignature::headers(config('topup.topup_api_secret'), 'POST', $payPath, $body), $body];
}, range(1, PARALLEL)));
check('同時送出付款只有一個成功', count(array_filter($codes, fn ($c) => $c === 200)) === 1 && count(array_filter($codes, fn ($c) => $c === 409)) === PARALLEL - 1, json_encode(array_count_values($codes)));
waitFor(fn () => $orders[0]->fresh()->status === 'fulfilled');
check('充值只付款一次', Payment::where('order_id', $orders[0]->id)->count() === 1);
check('充值只入點一次', $ledgerOf($buyer, 'topup') === 1 && (int) $buyer->wallet()->first()->balance === $orders[0]->points, 'balance='.$buyer->wallet()->first()->balance);

// 9. 帳本加總等於錢包。
$bad = DB::select('SELECT w.player_id FROM game_wallets w LEFT JOIN (SELECT player_id, SUM(delta_balance) b, SUM(delta_frozen) f FROM game_point_ledger GROUP BY player_id) l ON l.player_id = w.player_id WHERE w.balance <> COALESCE(l.b, 0) OR w.frozen <> COALESCE(l.f, 0)');
check('所有玩家的帳本都與錢包一致', $bad === [], json_encode($bad));

// 10. 帳務核對（等佇列把這次測試的工作做完）。
waitFor(fn () => app(ReconciliationService::class)->run()['ok'], 15);
$result = app(ReconciliationService::class)->run();
check('帳務核對通過', $result['ok'], json_encode($result['issues'], JSON_UNESCAPED_UNICODE));

exit(isset($GLOBALS['failed']) ? 1 : 0);
