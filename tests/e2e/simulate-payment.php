<?php

// 端對端測試用：玩家已停權、無法再開付款頁時，由模擬金流送出付款通知。
// 用法：php tests/e2e/simulate-payment.php <order_no> [scenario]

use App\Models\Order;
use App\Payments\MockGateway;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$order = Order::where('order_no', $argv[1] ?? '')->firstOrFail();
$app->make(MockGateway::class)->simulatePayment($order, $argv[2] ?? 'success');
echo "sent\n";
