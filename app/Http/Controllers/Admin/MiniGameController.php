<?php

namespace App\Http\Controllers\Admin;

use App\MiniGame\Models\MiniGameSetting;
use App\MiniGame\Models\WheelRound;
use App\MiniGame\Services\ReconciliationService;
use App\Models\AuditLog;
use App\Models\Order;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** 小遊戲後台：唯讀的紀錄、統計與帳務核對，以及覆核者的停用開關。 */
class MiniGameController
{
    public function index(Request $request, ReconciliationService $reconciliation): View
    {
        $filters = $request->validate([
            'player_no' => 'nullable|string|max:12',
            'status' => 'nullable|in:'.implode(',', array_keys(WheelRound::STATUS_LABELS)),
            'topup' => 'nullable|in:failed',
        ]);

        $rounds = WheelRound::where('status', '!=', 'committed')
            ->when($filters['player_no'] ?? null, fn ($q, $no) => $q->where('player_no', $no))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(30, ['*'], 'rounds')
            ->withQueryString();

        $topups = Order::where('source', 'game')
            ->with('player')
            ->when(($filters['topup'] ?? null) === 'failed', fn ($q) => $q->failedForPlayer())
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'topups')
            ->withQueryString();

        return view('admin.minigame', [
            'enabled' => MiniGameSetting::wheelEnabled(),
            'rounds' => $rounds,
            'topups' => $topups,
            'filters' => $filters,
            'statusLabels' => WheelRound::STATUS_LABELS,
            'stats' => $this->stats(),
            'held' => $this->heldRounds(),
            'check' => $reconciliation->run(),
        ]);
    }

    public function toggle(Request $request, TotpService $totp): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'reason' => 'required|string|max:200',
        ]);
        $admin = Auth::guard('admin')->user();

        if (! $totp->verify($admin, (string) $request->input('code'))) {
            return back()->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        $enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN);
        DB::transaction(function () use ($enabled, $data, $admin) {
            MiniGameSetting::whereKey(1)->update(['wheel_enabled' => $enabled]);
            AuditLog::record($enabled ? 'minigame.enabled' : 'minigame.disabled', null, ['note' => $data['reason']], $admin);
        });

        return back()->with('status', $enabled ? '轉盤已啟用' : '轉盤已停用');
    }

    /** 回報率只計已結算的局；局數少時本來就會有大幅波動，所以一併顯示局數。 */
    private function stats(): array
    {
        $counts = WheelRound::selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        $byBet = WheelRound::where('status', 'settled')
            ->selectRaw('bet_points, COUNT(*) AS rounds, SUM(bet_points) AS bet_total, SUM(payout_points) AS payout_total')
            ->groupBy('bet_points')
            ->orderBy('bet_points')
            ->get()
            ->map(fn ($row) => [
                'label' => number_format($row->bet_points).' 點',
                'rounds' => (int) $row->rounds,
                'bet_total' => (int) $row->bet_total,
                'payout_total' => (int) $row->payout_total,
            ])
            ->all();

        $total = [
            'label' => '全部',
            'rounds' => array_sum(array_column($byBet, 'rounds')),
            'bet_total' => array_sum(array_column($byBet, 'bet_total')),
            'payout_total' => array_sum(array_column($byBet, 'payout_total')),
        ];

        return [
            'rows' => [...$byBet, $total],
            'target' => config('minigame.wheel.target_rtp_pct'),
            'counts' => $counts,
            'overdue' => WheelRound::whereIn('status', WheelRound::IN_PROGRESS)
                ->where('updated_at', '<', now()->subMinutes((int) config('minigame.alert_after_minutes')))
                ->count(),
        ];
    }

    /** 暫停的局要連同帳本的實際紀錄一起看，才能判斷該補償多少。 */
    private function heldRounds(): array
    {
        return WheelRound::where('status', 'integrity_hold')->orderByDesc('id')->limit(50)->get()
            ->map(function (WheelRound $round) {
                $ledger = DB::table('game_point_ledger')->whereIn('idempotency_key', [
                    "debit:{$round->game_player_id}:{$round->debitKey()}",
                    "credit:{$round->game_player_id}:{$round->debitKey()}",
                    "void:debit:{$round->game_player_id}:{$round->debitKey()}",
                ])->orderBy('id')->get(['type', 'delta_balance', 'created_at']);

                return ['round' => $round, 'ledger' => $ledger];
            })
            ->all();
    }
}
