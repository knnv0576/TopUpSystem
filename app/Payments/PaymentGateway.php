<?php

namespace App\Payments;

use App\Models\Order;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function name(): string;

    /**
     * 帳戶驗證：確認帳號存在、戶名相符。
     *
     * @return string matched、account_not_found 或 name_mismatch
     */
    public function verifyAccount(string $bankCode, string $account, string $holderName): string;

    /** 回傳付款頁網址。 */
    public function createCheckout(Order $order): string;

    /**
     * 驗簽並解析 Webhook。簽章錯誤時回傳 null。
     *
     * @return array{event_id:string,type:string,provider_txn_id:?string,order_no:?string,payout_key:?string,amount:int,currency:string}|null
     */
    public function parseWebhook(Request $request): ?array;

    /**
     * 送出撥款，結果由 Webhook 回傳。同一個冪等鍵不會付款兩次。
     *
     * @throws \RuntimeException 暫時性錯誤，由呼叫端重試
     */
    public function payout(string $idempotencyKey, int $amount, string $bankCode, string $account): string;

    /** 查詢撥款狀態：succeeded、failed、pending；沒有紀錄時回傳 null。 */
    public function queryStatus(string $idempotencyKey): ?string;
}
