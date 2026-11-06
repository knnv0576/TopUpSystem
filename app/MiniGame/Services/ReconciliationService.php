<?php

namespace App\MiniGame\Services;

use App\MiniGame\Models\WheelRound;
use App\MiniGame\Support\RoundIntegrity;
use Illuminate\Support\Facades\DB;

/**
 * 帳務核對。只讀取、只比對，不修改任何資料。
 * 會同時讀玩家端與充值端的資料表，這是核對工具的例外，一般流程仍只走簽章 API。
 */
class ReconciliationService
{
    /**
     * @return array{ok: bool, issues: string[], checked: array<string, int>, in_progress: int, held: int}
     */
    public function run(): array
    {
        $issues = [
            ...$this->walletsMatchLedger(),
            ...$this->ordersMatchLedger(),
            ...$this->roundsMatchLedger(),
            ...$this->roundsAreIntact(),
        ];

        return [
            'ok' => $issues === [],
            'issues' => $issues,
            'checked' => [
                '錢包' => DB::table('game_wallets')->count(),
                '已完成訂單' => DB::table('orders')->where('status', 'fulfilled')->count(),
                '已開局的局' => WheelRound::where('status', '!=', 'committed')->count(),
            ],
            'in_progress' => WheelRound::whereIn('status', WheelRound::IN_PROGRESS)->count(),
            'held' => WheelRound::where('status', 'integrity_hold')->count(),
        ];
    }

    private function walletsMatchLedger(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT w.player_id, w.balance, w.frozen, COALESCE(l.b, 0) AS ledger_balance, COALESCE(l.f, 0) AS ledger_frozen
            FROM game_wallets w
            LEFT JOIN (SELECT player_id, SUM(delta_balance) b, SUM(delta_frozen) f FROM game_point_ledger GROUP BY player_id) l ON l.player_id = w.player_id
            WHERE w.balance <> COALESCE(l.b, 0) OR w.frozen <> COALESCE(l.f, 0)
        SQL);

        return array_map(
            fn ($r) => "玩家 #{$r->player_id} 的錢包（餘額 {$r->balance}、凍結 {$r->frozen}）與帳本加總（{$r->ledger_balance}、{$r->ledger_frozen}）不符",
            $rows,
        );
    }

    private function ordersMatchLedger(): array
    {
        $unmatched = DB::select(<<<'SQL'
            SELECT o.order_no, o.points, g.status AS grant_status, g.points AS grant_points, l.delta_balance
            FROM orders o
            JOIN players p ON p.id = o.player_id
            LEFT JOIN point_grants g ON g.order_id = o.id
            LEFT JOIN game_point_ledger l ON l.idempotency_key = 'grant:order:' || o.id AND l.player_id = p.game_player_id
            WHERE o.status = 'fulfilled'
              AND (g.id IS NULL OR g.status <> 'granted' OR g.points <> o.points OR l.id IS NULL OR l.delta_balance <> o.points)
        SQL);

        $orphans = DB::select(<<<'SQL'
            SELECT l.id, l.idempotency_key
            FROM game_point_ledger l
            WHERE l.type = 'topup' AND NOT EXISTS (
                SELECT 1 FROM orders o
                JOIN players p ON p.id = o.player_id
                WHERE l.idempotency_key = 'grant:order:' || o.id AND p.game_player_id = l.player_id AND o.status IN ('paid', 'fulfilled')
            )
        SQL);

        return [
            ...array_map(fn ($r) => "訂單 {$r->order_no} 已完成，但入點或帳本紀錄與 {$r->points} 點不符", $unmatched),
            ...array_map(fn ($r) => "帳本 #{$r->id} 有充值入點，卻找不到對應的已付款訂單", $orphans),
        ];
    }

    private function roundsMatchLedger(): array
    {
        $rounds = DB::select(<<<'SQL'
            SELECT r.public_id, r.status, r.bet_points, r.payout_points, d.delta_balance AS debit, c.delta_balance AS credit
            FROM minigame_rounds r
            LEFT JOIN game_point_ledger d ON d.idempotency_key = 'debit:' || r.game_player_id || ':wheel-' || r.public_id
            LEFT JOIN game_point_ledger c ON c.idempotency_key = 'credit:' || r.game_player_id || ':wheel-' || r.public_id
            WHERE r.status IN ('payout_pending', 'settled', 'rejected')
        SQL);

        $issues = [];
        foreach ($rounds as $r) {
            $debit = $r->debit === null ? null : (int) $r->debit;
            $credit = $r->credit === null ? null : (int) $r->credit;

            if ($r->status === 'rejected') {
                if ($debit !== null || $credit !== null) {
                    $issues[] = "局 {$r->public_id} 未成立，帳本卻有異動";
                }

                continue;
            }
            if ($debit !== -(int) $r->bet_points) {
                $issues[] = "局 {$r->public_id} 的下注額 {$r->bet_points} 與帳本不符";
            }
            if ($r->status === 'settled' && ($credit ?? 0) !== (int) $r->payout_points) {
                $issues[] = "局 {$r->public_id} 的獎勵 {$r->payout_points} 與帳本不符";
            }
        }

        $orphans = DB::select(<<<'SQL'
            SELECT l.id
            FROM game_point_ledger l
            WHERE l.type = 'game_credit' AND NOT EXISTS (
                SELECT 1 FROM minigame_rounds r
                WHERE l.idempotency_key = 'credit:' || r.game_player_id || ':wheel-' || r.public_id
                  AND r.status IN ('payout_pending', 'settled', 'integrity_hold')
            )
        SQL);

        // 作廢後不應該還有扣點；作廢紀錄本身也必須是零異動。
        $voided = DB::select(<<<'SQL'
            SELECT v.id
            FROM game_point_ledger v
            WHERE v.type = 'game_debit_void'
              AND (v.delta_balance <> 0 OR v.delta_frozen <> 0
                   OR EXISTS (SELECT 1 FROM game_point_ledger d WHERE 'void:' || d.idempotency_key = v.idempotency_key))
        SQL);

        return [
            ...$issues,
            ...array_map(fn ($r) => "帳本 #{$r->id} 有遊戲獎勵，卻找不到對應的局", $orphans),
            ...array_map(fn ($r) => "帳本 #{$r->id} 的扣點已作廢，卻仍有點數異動", $voided),
        ];
    }

    /** integrity_hold 的局已經被標記並保留作證據，另外計數，不重複列為新問題。 */
    private function roundsAreIntact(): array
    {
        $issues = [];
        WheelRound::whereNotIn('status', ['committed', 'integrity_hold'])->lazyById(200)->each(function (WheelRound $round) use (&$issues) {
            if (! RoundIntegrity::isValid($round)) {
                $issues[] = "局 {$round->public_id} 的檢查碼不符";
            }
        });

        return $issues;
    }
}
