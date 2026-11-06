<?php

use App\DemoGame\Services\RedPacketService;
use App\Jobs\GrantPoints;
use App\MiniGame\Services\ReconciliationService;
use App\MiniGame\Services\WheelService;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\PointGrant;
use App\Services\OrderService;
use App\Services\RefundService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('topup:expire-orders', function (OrderService $orders) {
    $this->info('expired orders: '.$orders->expireDue());
})->purpose('讓逾時未付款的訂單過期');

Artisan::command('topup:expire-red-packets', function (RedPacketService $packets) {
    $this->info('expired red packets: '.$packets->expireDue());
})->purpose('退回逾時未領的紅包');

Artisan::command('topup:reconcile', function (RefundService $refunds) {
    PointGrant::where('status', 'pending')->where('updated_at', '<', now()->subMinutes(10))->pluck('id')
        ->each(fn ($id) => GrantPoints::dispatch($id));
    $refunds->reconcile();
})->purpose('補做卡住的入點與退款');

Artisan::command('topup:prune', function () {
    DB::table('api_nonces')->where('created_at', '<', now()->subHour())->delete();
    DB::table('sso_tokens')->where('expires_at', '<', now()->subHour())->delete();
})->purpose('清除過期的 nonce 與單次 token 紀錄');

Artisan::command('minigame:reconcile', function (WheelService $wheel) {
    $this->info('retried rounds: '.$wheel->reconcile());
})->purpose('補做中斷的轉盤局');

Artisan::command('minigame:check', function (ReconciliationService $reconciliation) {
    $result = $reconciliation->run();

    foreach ($result['checked'] as $name => $count) {
        $this->line("{$name}：{$count} 筆");
    }
    $this->line("處理中的局：{$result['in_progress']} 筆；檢查碼不符而暫停的局：{$result['held']} 筆");

    foreach ($result['issues'] as $issue) {
        $this->error($issue);
    }
    $this->line($result['ok'] ? '帳務核對通過' : '帳務核對發現 '.count($result['issues']).' 項不一致');

    return $result['ok'] ? 0 : 1;
})->purpose('唯讀核對帳本、錢包、訂單與轉盤局是否一致');

// 主機管理者用：忘記密碼或遺失 TOTP。新密碼只顯示一次，登入後必須更改。
Artisan::command('topup:admin-reset {username} {--password : 產生新的隨機密碼} {--totp : 清除 TOTP 綁定，下次登入重新綁定}', function (string $username) {
    $admin = AdminUser::where('username', $username)->first();
    if (! $admin) {
        $this->error('找不到這個帳號');

        return 1;
    }
    if (! $this->option('password') && ! $this->option('totp')) {
        $this->error('請指定 --password 或 --totp');

        return 1;
    }

    $changes = ['failed_attempts' => 0, 'locked_until' => null];
    $password = null;
    if ($this->option('password')) {
        $password = Str::password(20, symbols: false);
        $changes += ['password' => $password, 'must_change_password' => true];
    }
    if ($this->option('totp')) {
        $changes += ['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_timestamp' => null];
    }
    $admin->update($changes);

    AuditLog::record('admin.reset_by_cli', $admin, [
        'actor_type' => 'cli',
        'note' => implode('、', array_filter([$this->option('password') ? '重設密碼' : null, $this->option('totp') ? '清除 TOTP' : null])),
    ]);

    $this->info('已重設帳號：'.$admin->username);
    if ($password) {
        $this->line('password: '.$password);
    }

    return 0;
})->purpose('重設管理員的密碼或 TOTP');

Schedule::command('topup:expire-orders')->everyMinute()->withoutOverlapping();
Schedule::command('topup:expire-red-packets')->everyMinute()->withoutOverlapping();
Schedule::command('topup:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('minigame:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('topup:prune')->hourly();
