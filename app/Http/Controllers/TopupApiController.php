<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Package;
use App\Models\Player;
use App\Models\RefundRequest;
use App\Services\GameTopupService;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'bank_code' => 'required|digits:3',
            'bank_account' => 'required|digits_between:6,16',
        ]);

        try {
            $refund = $refunds->submit($data['game_request_id'], $data['game_player_id'], $data['points'], $data['bank_code'], $data['bank_account']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->present($refund), 201);
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

    /** 遊戲內充值。這裡不接受付款情境參數，結果只由伺服器決定。 */
    public function createGameOrder(Request $request, int $gamePlayerId, GameTopupService $topups): JsonResponse
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'package_id' => 'required|integer']);

        try {
            $order = $topups->create($gamePlayerId, $data['request_id'], $data['package_id']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->presentGameOrder($order), 201);
    }

    public function gameOrder(int $gamePlayerId, string $orderNo): JsonResponse
    {
        $order = Order::where('order_no', $orderNo)
            ->where('source', 'game')
            ->whereHas('player', fn ($q) => $q->where('game_player_id', $gamePlayerId))
            ->first();

        return $order
            ? response()->json($this->presentGameOrder($order))
            : response()->json(['error' => 'not_found'], 404);
    }

    private function presentGameOrder(Order $order): array
    {
        $state = match (true) {
            $order->status === 'fulfilled' => 'success',
            $order->failedForPlayer() => 'failed',
            default => 'processing',
        };

        return [
            'order_no' => $order->order_no,
            'package_name' => $order->package_name,
            'amount' => $order->amount,
            'points' => $order->points,
            'state' => $state,
        ];
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
            'created_at' => $refund->created_at->toIso8601String(),
        ];
    }
}
