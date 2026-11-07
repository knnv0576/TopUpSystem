<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Package;
use App\Models\Player;
use App\Models\PlayerBankAccount;
use App\Models\RefundRequest;
use App\Payments\MockCheckout;
use App\Services\BankAccountService;
use App\Services\GameTopupService;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** 玩家端呼叫的簽章 API。回傳內容不含審核層級、管理員身分與完整帳號。 */
class TopupApiController
{
    public function submitRefund(Request $request, RefundService $refunds): JsonResponse
    {
        $data = $request->validate([
            'game_request_id' => 'required|uuid',
            'game_player_id' => 'required|integer|min:1',
            'points' => 'required|integer|min:1',
            'bank_account_id' => 'required|integer|min:1',
        ]);

        // 撥款帳戶只能從玩家自己綁定的帳戶選，不接受直接傳入帳號。
        $player = Player::forGame($data['game_player_id']);
        $bankAccount = PlayerBankAccount::where('player_id', $player->id)->find($data['bank_account_id']);
        if (! $bankAccount) {
            return response()->json(['error' => '找不到這個撥款帳戶，請重新選擇'], 422);
        }
        if (! $bankAccount->usable()) {
            return response()->json(['error' => '這個帳戶曾被銀行退匯，請刪除後重新綁定正確的帳戶'], 422);
        }

        try {
            $refund = $refunds->submit($data['game_request_id'], $data['game_player_id'], $data['points'], $bankAccount->bank_code, $bankAccount->account_encrypted, $bankAccount);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->present($refund), 201);
    }

    public function bankAccounts(int $gamePlayerId, BankAccountService $accounts): JsonResponse
    {
        return response()->json(['data' => $accounts->list(Player::forGame($gamePlayerId))->map->present()->values()]);
    }

    public function addBankAccount(Request $request, int $gamePlayerId, BankAccountService $accounts): JsonResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:30',
            'bank_code' => 'required|digits:3',
            'bank_account' => 'required|digits_between:6,16',
            'holder_name' => ['required', 'string', 'max:50', 'regex:/^[\p{Han}A-Za-z][\p{Han}A-Za-z ·.\-]{1,49}$/u'],
        ]);

        try {
            $account = $accounts->add(Player::forGame($gamePlayerId), $data['label'], $data['bank_code'], $data['bank_account'], $data['holder_name']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($account->present(), 201);
    }

    public function deleteBankAccount(int $gamePlayerId, int $accountId, BankAccountService $accounts): JsonResponse
    {
        $accounts->remove(Player::forGame($gamePlayerId), $accountId);

        return response()->json(['ok' => true]);
    }

    /** 玩家改密碼：這個時間之前建立的充值網站 session 全部失效。 */
    public function revokeSessions(int $gamePlayerId): JsonResponse
    {
        $player = Player::forGame($gamePlayerId);
        $player->update(['sessions_revoked_at' => now()]);
        AuditLog::record('player.sessions_revoked', $player, ['actor_type' => 'player', 'actor' => (string) $gamePlayerId, 'note' => '玩家更改密碼']);

        return response()->json(['ok' => true]);
    }

    public function refundRequests(int $gamePlayerId): JsonResponse
    {
        $player = Player::where('game_player_id', $gamePlayerId)->first();
        $refunds = $player
            ? RefundRequest::where('player_id', $player->id)->orderByDesc('id')->limit(50)->get()->map(fn ($r) => $this->present($r))
            : [];

        return response()->json(['data' => $refunds]);
    }

    public function orders(int $gamePlayerId): JsonResponse
    {
        $player = Player::where('game_player_id', $gamePlayerId)->first();
        $orders = $player
            ? Order::where('player_id', $player->id)->orderByDesc('id')->limit(50)->get()->map(fn (Order $o) => [
                'order_no' => $o->order_no,
                'package_name' => $o->package_name,
                'amount' => $o->amount,
                'points' => $o->points,
                'status' => $o->playerStatusLabel(),
                'created_at' => $o->created_at->toIso8601String(),
            ])
            : [];

        return response()->json(['data' => $orders]);
    }

    public function packages(): JsonResponse
    {
        $packages = Package::where('active', true)->orderBy('price')->get()
            ->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price, 'points' => $p->points()]);

        return response()->json(['data' => $packages]);
    }

    /** 遊戲內充值：只建立訂單，付款另外送出。不接受付款情境參數，結果由付款資料決定。 */
    public function createGameOrder(Request $request, int $gamePlayerId, GameTopupService $topups): JsonResponse
    {
        $data = $request->validate([
            'request_id' => 'required|uuid',
            'package_id' => 'required|integer',
            'method' => ['required', Rule::in(array_keys(Order::PAYMENT_METHODS))],
        ]);

        try {
            $order = $topups->create($gamePlayerId, $data['request_id'], $data['package_id'], $data['method']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->presentGameOrder($order), 201);
    }

    public function gameOrder(int $gamePlayerId, string $orderNo): JsonResponse
    {
        $order = $this->findGameOrder($gamePlayerId, $orderNo);

        return $order
            ? response()->json($this->presentGameOrder($order))
            : response()->json(['error' => 'not_found'], 404);
    }

    /** 付款資料只交給驗證，不寫入資料庫或日誌；錯誤訊息不帶出輸入的內容。 */
    public function payGameOrder(Request $request, int $gamePlayerId, string $orderNo, GameTopupService $topups): JsonResponse
    {
        $order = $this->findGameOrder($gamePlayerId, $orderNo);
        if (! $order) {
            return response()->json(['error' => 'not_found'], 404);
        }

        try {
            $errors = $topups->pay($order, $request->only(MockCheckout::PAYMENT_FIELDS));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return $errors === []
            ? response()->json($this->presentGameOrder($order->fresh()))
            : response()->json(['error' => 'invalid_payment', 'errors' => $errors], 422);
    }

    private function findGameOrder(int $gamePlayerId, string $orderNo): ?Order
    {
        return Order::where('order_no', $orderNo)
            ->where('source', 'game')
            ->whereHas('player', fn ($q) => $q->where('game_player_id', $gamePlayerId))
            ->first();
    }

    private function presentGameOrder(Order $order): array
    {
        $state = match (true) {
            $order->status === 'fulfilled' => 'success',
            $order->failedForPlayer() => 'failed',
            $order->isPayable() => 'awaiting_payment',
            $order->status === 'expired' => 'expired',
            default => 'processing',
        };

        return [
            'order_no' => $order->order_no,
            'package_name' => $order->package_name,
            'amount' => $order->amount,
            'points' => $order->points,
            'state' => $state,
        ] + ($state === 'awaiting_payment' ? ['payment' => $this->paymentInstructions($order)] : []);
    }

    /** 彈窗顯示的付款資訊：與付款頁相同，由訂單導出。 */
    private function paymentInstructions(Order $order): array
    {
        return ['method' => $order->payment_method, 'expires_at' => $order->expires_at->format('Y-m-d H:i')] + match ($order->payment_method) {
            'card' => ['test_cards' => array_map(
                fn (string $number, array $card) => ['number' => $number, 'display' => MockCheckout::group($number), 'brand' => MockCheckout::BRANDS[$card[0]], 'label' => $card[2]],
                array_keys(MockCheckout::TEST_CARDS),
                MockCheckout::TEST_CARDS,
            )],
            'atm' => ['bank' => MockCheckout::ATM_BANK_CODE, 'account' => MockCheckout::virtualAccount($order)],
            'cvs' => ['code' => MockCheckout::storeCode($order), 'limit' => MockCheckout::CVS_LIMIT],
        };
    }

    private function present(RefundRequest $refund): array
    {
        return [
            'id' => $refund->id,
            'points' => $refund->points,
            'amount' => $refund->amount,
            'status' => RefundRequest::PLAYER_LABELS[$refund->status] ?? '處理中',
            'reason' => in_array($refund->status, ['rejected', 'closed_points_returned'], true) ? $refund->reject_reason : null,
            'account' => $refund->maskedAccount(),
            'account_label' => $refund->bank_label,
            'freezing' => $refund->status === 'pending_freeze',
            'created_at' => $refund->created_at->toIso8601String(),
        ];
    }
}
