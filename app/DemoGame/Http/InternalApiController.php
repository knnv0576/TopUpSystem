<?php

namespace App\DemoGame\Http;

use App\DemoGame\Exceptions\DebitVoided;
use App\DemoGame\Exceptions\InsufficientPoints;
use App\DemoGame\Models\GamePlayer;
use App\DemoGame\Services\RedPacketService;
use App\DemoGame\Services\WalletService;
use App\MiniGame\Support\WheelOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 玩家端的簽章 API：供充值端發放、凍結、調整點數與停權，以及之後的小遊戲扣點。
 */
class InternalApiController
{
    public function __construct(private WalletService $wallets, private RedPacketService $packets) {}

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => 'nullable|integer', 'player_no' => 'nullable|string|max:12']);

        $query = GamePlayer::with('wallet');
        $player = isset($data['id'])
            ? $query->find($data['id'])
            : (isset($data['player_no']) ? $query->where('player_no', $data['player_no'])->first() : null);

        if (! $player) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'id' => $player->id,
            'player_no' => $player->player_no,
            'nickname' => $player->nickname,
            'balance' => (int) $player->wallet->balance,
            'frozen' => (int) $player->wallet->frozen,
            'suspended' => $player->isSuspended(),
        ]);
    }

    public function grant(Request $request, GamePlayer $player): JsonResponse
    {
        $data = $this->validated($request, ['key' => 'required|string|max:100', 'memo' => 'nullable|string|max:200']);
        $this->wallets->credit($player->id, $data['points'], 'topup', $data['key'], $data['memo'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function freeze(Request $request, GamePlayer $player): JsonResponse
    {
        $data = $this->validated($request, ['ref' => 'required|string|max:100']);

        return $this->guardInsufficient(fn () => $this->wallets->freeze($player->id, $data['points'], $data['ref']));
    }

    public function unfreeze(Request $request, GamePlayer $player): JsonResponse
    {
        $data = $this->validated($request, ['ref' => 'required|string|max:100']);
        $this->wallets->unfreeze($player->id, $data['points'], $data['ref']);

        return response()->json(['ok' => true]);
    }

    public function deductFrozen(Request $request, GamePlayer $player): JsonResponse
    {
        $data = $this->validated($request, ['ref' => 'required|string|max:100']);
        $this->wallets->deductFrozen($player->id, $data['points'], $data['ref']);

        return response()->json(['ok' => true]);
    }

    public function returnDeducted(Request $request, GamePlayer $player): JsonResponse
    {
        $data = $this->validated($request, ['ref' => 'required|string|max:100']);
        $this->wallets->returnDeducted($player->id, $data['points'], $data['ref']);

        return response()->json(['ok' => true]);
    }

    public function adjust(Request $request, GamePlayer $player): JsonResponse
    {
        $data = $this->validated($request, ['direction' => 'required|in:add,deduct', 'key' => 'required|string|max:100']);

        return $this->guardInsufficient(function () use ($player, $data) {
            if ($data['direction'] === 'add') {
                $this->wallets->credit($player->id, $data['points'], 'admin_adjust', $data['key'], '人工調整');
            } else {
                $this->wallets->debit($player->id, $data['points'], 'admin_adjust', $data['key'], '人工調整');
            }
        });
    }

    /** 停權：立即阻擋登入，並退回所有相關的未領紅包。可安全重複呼叫。 */
    public function suspend(GamePlayer $player): JsonResponse
    {
        DB::transaction(function () use ($player) {
            GamePlayer::whereKey($player->id)->whereNull('suspended_at')->update(['suspended_at' => now()]);
            $this->packets->returnAllFor($player->id);
        });

        return response()->json(['ok' => true]);
    }

    public function unsuspend(GamePlayer $player): JsonResponse
    {
        GamePlayer::whereKey($player->id)->update(['suspended_at' => null]);

        return response()->json(['ok' => true]);
    }

    /** 給小遊戲使用的扣點 API：只扣可用餘額，相同冪等鍵回傳原結果。 */
    public function debit(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'player_no' => 'required|string|max:12',
            'idempotency_key' => 'required|string|max:80',
            'purpose' => 'required|string|max:100',
        ]);

        $player = GamePlayer::where('player_no', $data['player_no'])->first();
        if (! $player) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // 冪等鍵以玩家區分：不同玩家用到相同的鍵，仍然要各自扣點。
        $key = "debit:{$player->id}:{$data['idempotency_key']}";

        // 先查帳本再查停權：已成功的扣點重試時要回成功，呼叫端才不會誤判成沒有扣點。
        if ($replay = $this->replayResponse($key, $data['points'])) {
            return $replay;
        }
        if (DB::table('game_point_ledger')->where('idempotency_key', "void:$key")->exists()) {
            return response()->json(['error' => 'debit_voided'], 409);
        }
        if ($player->isSuspended()) {
            return response()->json(['error' => 'player_suspended'], 403);
        }

        $response = $this->guardInsufficient(function () use ($player, $data, $key) {
            $this->wallets->debit($player->id, $data['points'], 'game_debit', $key, $data['purpose']);
        });

        // 併發時可能由另一個不同點數的請求先寫入，這裡要再確認一次。
        return $response->isOk() ? ($this->replayResponse($key, $data['points']) ?? $response) : $response;
    }

    /**
     * 作廢扣點：呼叫端要判定扣點失敗前先呼叫。扣點已經發生就回報 debited；
     * 還沒發生就作廢並回報 voided，之後晚到的同鍵扣點回 409 debit_voided。
     */
    public function voidDebit(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'player_no' => 'required|string|max:12',
            'idempotency_key' => 'required|string|max:80',
        ]);

        $player = GamePlayer::where('player_no', $data['player_no'])->first();
        if (! $player) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $key = "debit:{$player->id}:{$data['idempotency_key']}";
        if ($this->wallets->voidDebit($player->id, $key)) {
            return response()->json(['status' => 'voided']);
        }

        return $this->replayResponse($key, $data['points'])->isOk()
            ? response()->json(['status' => 'debited'])
            : response()->json(['error' => 'idempotency_conflict'], 409);
    }

    /**
     * 給小遊戲使用的加點 API：只能針對已扣點的下注發獎，一筆下注最多發獎一次。
     * 已下注的局要結清，所以不拒絕停權玩家；新的下注由扣點 API 擋下。
     */
    public function credit(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'player_no' => 'required|string|max:12',
            'debit_idempotency_key' => 'required|string|max:80',
            'purpose' => 'required|string|max:100',
        ]);

        $player = GamePlayer::where('player_no', $data['player_no'])->first();
        if (! $player) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $debit = DB::table('game_point_ledger')
            ->where('idempotency_key', "debit:{$player->id}:{$data['debit_idempotency_key']}")
            ->where('type', 'game_debit')
            ->first();
        if (! $debit) {
            return response()->json(['error' => 'debit_not_found'], 409);
        }

        // 上限來自帳本的實際下注額，局資料被改也發不出超過賠率表的獎勵。
        if ($data['points'] > WheelOutcome::payout(abs((int) $debit->delta_balance), WheelOutcome::maxMultiplierPct())) {
            return response()->json(['error' => 'payout_exceeds_limit'], 409);
        }

        $key = "credit:{$player->id}:{$data['debit_idempotency_key']}";
        if ($replay = $this->replayResponse($key, $data['points'])) {
            return $replay;
        }

        $this->wallets->credit($player->id, $data['points'], 'game_credit', $key, $data['purpose']);

        return $this->replayResponse($key, $data['points']) ?? response()->json(['ok' => true]);
    }

    /** 帳本已有這個鍵時：點數相同回成功，不同回衝突；沒有就回 null。 */
    private function replayResponse(string $key, int $points): ?JsonResponse
    {
        $entry = DB::table('game_point_ledger')->where('idempotency_key', $key)->first();
        if (! $entry) {
            return null;
        }

        return abs((int) $entry->delta_balance) === $points
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'idempotency_conflict'], 409);
    }

    private function validated(Request $request, array $rules): array
    {
        return $request->validate(['points' => 'required|integer|min:1'] + $rules);
    }

    private function guardInsufficient(callable $action): JsonResponse
    {
        try {
            $action();
        } catch (InsufficientPoints) {
            return response()->json(['error' => 'insufficient_points'], 422);
        } catch (DebitVoided) {
            return response()->json(['error' => 'debit_voided'], 409);
        }

        return response()->json(['ok' => true]);
    }
}
