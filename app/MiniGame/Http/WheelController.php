<?php

namespace App\MiniGame\Http;

use App\DemoGame\Services\TopupClient;
use App\MiniGame\Exceptions\SpinRefused;
use App\MiniGame\Models\MiniGameSetting;
use App\MiniGame\Models\WheelRound;
use App\MiniGame\Services\WheelService;
use App\MiniGame\Support\WheelOutcome;
use App\Support\PlayerMessages;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** 玩家的轉盤畫面。結果與獎勵都只由伺服器計算，請求只帶檔位和玩家的種子。 */
class WheelController
{
    /** 遊戲內充值轉送給充值端的付款資料欄位，其他輸入一律不轉送。 */
    private const PAYMENT_FIELDS = ['card_name', 'card_number', 'card_expiry', 'card_cvv', 'atm_bank', 'atm_account', 'atm_amount', 'cvs_code', 'cvs_amount'];

    public function __construct(private WheelService $wheel, private TopupClient $topup) {}

    public function index(): View
    {
        $player = Auth::guard('game')->user();

        try {
            $packages = $this->topup->packages();
        } catch (Throwable) {
            $packages = []; // 充值端暫時連不上時，遊戲仍可使用。
        }

        return view('game.wheel', [
            'wallet' => $player->wallet,
            'round' => $this->wheel->ensureCommitted($player),
            'enabled' => MiniGameSetting::wheelEnabled(),
            'bets' => config('minigame.wheel.bets'),
            'sectors' => WheelOutcome::sectors(),
            'packages' => $packages,
        ]);
    }

    public function spin(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'bet' => ['required', 'integer', Rule::in(config('minigame.wheel.bets'))],
            'client_seed' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ], [
            'bet.*' => '下注檔位不正確',
            'client_seed.*' => '玩家種子須為 8 到 64 個英數字元',
        ]);
        $player = Auth::guard('game')->user();

        try {
            $round = $this->wheel->spin($player, $publicId, (int) $data['bet'], $data['client_seed']);
        } catch (SpinRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json($this->present($round));
    }

    /** 查詢一局的現況；只讀取，補做由排程負責。 */
    public function show(string $publicId): JsonResponse
    {
        $round = WheelRound::where('public_id', $publicId)
            ->where('game_player_id', Auth::guard('game')->id())
            ->firstOrFail();

        return response()->json($this->present($round));
    }

    public function rounds(): View
    {
        return view('game.wheel-rounds', [
            'rounds' => WheelRound::where('game_player_id', Auth::guard('game')->id())
                ->where('status', '!=', 'committed')
                ->orderByDesc('id')
                ->paginate(30),
        ]);
    }

    /** 建立訂單，還不付款；付款資料由彈窗另外送出。 */
    public function createTopup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id' => 'required|integer',
            'request_id' => 'required|uuid',
            'method' => 'required|in:card,atm,cvs',
        ], ['method.*' => '請選擇付款方式']);

        try {
            $response = $this->topup->createGameOrder(Auth::guard('game')->id(), $data['request_id'], (int) $data['package_id'], $data['method']);
        } catch (ConnectionException) {
            return $this->topupUnknown();
        }

        return match (true) {
            $response->successful() => response()->json($this->presentTopup($response->json()), 201),
            $response->status() === 422 => response()->json(['message' => $response->json('error') ?? PlayerMessages::TOPUP_FAILED], 422),
            default => $this->topupUnknown(),
        };
    }

    /**
     * 把付款資料轉送給充值端驗證並付款。卡號與安全碼只經過這個請求，不寫入資料庫、日誌或 session。
     * 連線中斷時不確定有沒有送出，讓玩家查詢狀態，不自動重送，避免重複付款。
     */
    public function payTopup(Request $request, string $orderNo): JsonResponse
    {
        try {
            $response = $this->topup->payGameOrder(Auth::guard('game')->id(), $orderNo, $request->only(self::PAYMENT_FIELDS));
        } catch (ConnectionException) {
            return response()->json(['message' => '連線異常，無法確認付款是否送出，請稍候查看充值結果', 'uncertain' => true], 503);
        }

        return match (true) {
            $response->successful() => response()->json($this->presentTopup($response->json())),
            $response->status() === 404 => response()->json(['message' => '找不到這筆訂單'], 404),
            $response->status() === 422 => response()->json(['message' => '付款資料有誤，請檢查後再送出', 'errors' => $response->json('errors') ?? []], 422),
            $response->status() === 409 => response()->json(['message' => $response->json('error') ?? PlayerMessages::TOPUP_FAILED], 409),
            default => response()->json(['message' => '連線異常，無法確認付款是否送出，請稍候查看充值結果', 'uncertain' => true], 503),
        };
    }

    public function showTopup(string $orderNo): JsonResponse
    {
        try {
            $response = $this->topup->gameOrder(Auth::guard('game')->id(), $orderNo);
        } catch (ConnectionException) {
            return response()->json(['state' => 'processing', 'message' => '充值處理中']);
        }

        if ($response->status() === 404) {
            return response()->json(['message' => '找不到這筆訂單'], 404);
        }

        return $response->successful()
            ? response()->json($this->presentTopup($response->json()))
            : response()->json(['state' => 'processing', 'message' => '充值處理中']);
    }

    /** 不確定訂單有沒有建立：玩家再按一次會沿用同一個 request_id，不會重複充值。 */
    private function topupUnknown(): JsonResponse
    {
        return response()->json(['message' => '連線異常，請再按一次，不會重複充值'], 503);
    }

    private function presentTopup(array $order): array
    {
        return [
            'order_no' => $order['order_no'],
            'state' => $order['state'],
            'message' => match ($order['state']) {
                'success' => '充值完成，已取得 '.number_format($order['points']).' 點',
                'failed' => PlayerMessages::TOPUP_FAILED,
                'awaiting_payment' => '請輸入付款資料',
                'expired' => '訂單已逾期，沒有付款，請重新充值',
                default => '充值處理中',
            },
            'available' => $this->available(),
        ] + (isset($order['payment']) ? [
            'package_name' => $order['package_name'],
            'amount' => $order['amount'],
            'points' => $order['points'],
            'payment' => $order['payment'],
        ] : []);
    }

    /** 依狀態決定能給玩家看什麼：扣點成功前沒有結果，結算前沒有伺服器種子。 */
    private function present(WheelRound $round): array
    {
        $player = Auth::guard('game')->user();
        $data = ['public_id' => $round->public_id, 'available' => $this->available()];

        $data += match ($round->status) {
            'debit_pending' => ['state' => 'processing', 'message' => '處理中，請稍候'],
            'rejected' => ['state' => 'rejected', 'message' => $round->rejectMessage()],
            'integrity_hold' => ['state' => 'held', 'message' => PlayerMessages::ROUND_HELD],
            'payout_pending' => ['state' => 'crediting'] + $this->result($round),
            'settled' => ['state' => 'settled'] + $this->result($round) + [
                'server_seed' => $round->server_seed,
                'server_seed_hash' => $round->server_seed_hash,
                'client_seed' => $round->client_seed,
            ],
            default => ['state' => 'committed', 'message' => ''],
        };

        $next = $this->wheel->ensureCommitted($player);

        return $data + ['next' => ['public_id' => $next->public_id, 'server_seed_hash' => $next->server_seed_hash]];
    }

    private function result(WheelRound $round): array
    {
        $summary = WheelOutcome::summary($round->bet_points, $round->payout_points);

        return [
            'roll' => $round->roll,
            'bet' => $round->bet_points,
            'payout' => $round->payout_points,
            'outcome' => $summary['outcome'],
            'net' => $summary['net'],
            'message' => $summary['text'],
        ];
    }

    private function available(): int
    {
        return Auth::guard('game')->user()->wallet()->first()->available();
    }
}
