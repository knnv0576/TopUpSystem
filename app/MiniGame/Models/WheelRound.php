<?php

namespace App\MiniGame\Models;

use App\Support\PlayerMessages;
use Illuminate\Database\Eloquent\Model;

/** 轉盤的一局。開局後的內容由資料庫觸發器鎖定。 */
class WheelRound extends Model
{
    public const IN_PROGRESS = ['debit_pending', 'payout_pending'];

    public const STATUS_LABELS = [
        'committed' => '待開局',
        'debit_pending' => '扣點處理中',
        'payout_pending' => '發獎處理中',
        'settled' => '已結算',
        'rejected' => '未成立',
        'integrity_hold' => '檢查碼不符，已暫停',
    ];

    /** 玩家看到的未成立原因。每一種都沒有扣點：判定前會先作廢扣點。 */
    public const REJECT_MESSAGES = [
        'insufficient_points' => '可用點數不足，這一局沒有成立，也沒有扣點',
        'player_suspended' => PlayerMessages::SUSPENDED,
        'timeout' => '處理逾時，這一局沒有成立，也沒有扣點',
    ];

    public const REJECT_REASON_LABELS = [
        'insufficient_points' => '點數不足',
        'player_suspended' => '停權',
        'timeout' => '扣點逾時，已作廢',
    ];

    protected $table = 'minigame_rounds';

    protected $guarded = [];

    protected $hidden = ['server_seed', 'integrity_mac'];

    protected $casts = [
        'server_seed' => 'encrypted',
        'game_player_id' => 'integer',
        'bet_points' => 'integer',
        'roll' => 'integer',
        'multiplier_pct' => 'integer',
        'payout_points' => 'integer',
        'spun_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function debitKey(): string
    {
        return "wheel-{$this->public_id}";
    }

    public function statusLabel(): string
    {
        $label = self::STATUS_LABELS[$this->status] ?? $this->status;

        return $this->status === 'rejected' && $this->reject_reason
            ? $label.'（'.(self::REJECT_REASON_LABELS[$this->reject_reason] ?? $this->reject_reason).'）'
            : $label;
    }

    public function rejectMessage(): string
    {
        return self::REJECT_MESSAGES[$this->reject_reason] ?? '這一局沒有成立，也沒有扣點';
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::IN_PROGRESS, true)
            && $this->updated_at->lt(now()->subMinutes((int) config('minigame.alert_after_minutes')));
    }
}
