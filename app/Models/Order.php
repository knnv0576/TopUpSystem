<?php

namespace App\Models;

use App\Support\PlayerMessages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    public const STATUS_LABELS = [
        'pending' => '待付款',
        'paid' => '已付款，入點中',
        'fulfilled' => '已完成',
        'expired' => '已過期',
        'failed' => '付款失敗',
    ];

    /** 選方案時可選的付款方式。 */
    public const PAYMENT_METHODS = ['card' => '信用卡', 'atm' => 'ATM 虛擬帳號', 'cvs' => '超商代碼'];

    /** 金流通知可帶的失敗原因代碼。 */
    public const FAILURE_CODES = ['card_declined'];

    protected $guarded = [];

    protected $casts = ['amount' => 'integer', 'points' => 'integer', 'expires_at' => 'datetime', 'payment_submitted_at' => 'datetime'];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function grant(): HasOne
    {
        return $this->hasOne(PointGrant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statusLabel(): string
    {
        $label = self::STATUS_LABELS[$this->status] ?? $this->status;

        return $this->status === 'failed' && $this->failure_code === 'card_declined' ? $label.'（發卡行拒絕）' : $label;
    }

    /** 玩家看到的失敗不分原因；實際狀態只在後台顯示。 */
    public function playerStatusLabel(): string
    {
        if ($this->status === 'failed' && $this->failure_code === 'card_declined') {
            return PlayerMessages::CARD_DECLINED;
        }

        return $this->failedForPlayer() ? PlayerMessages::TOPUP_FAILED : $this->statusLabel();
    }

    public function failedForPlayer(): bool
    {
        if ($this->status === 'fulfilled') {
            return false;
        }

        // 送出付款後卻逾期，代表沒等到付款結果；沒送出付款就逾期只是放棄。
        return $this->status === 'failed'
            || ($this->status === 'expired' && $this->payment_submitted_at !== null)
            || $this->grant()->where('status', 'failed')->exists()
            || $this->payments()->where('status', 'amount_mismatch')->exists();
    }

    /**
     * 玩家還能不能付款。收過金額不符的通知時可能已經有款項入帳，
     * 再付一次可能重複收款，所以改由客服處理；遊戲內充值送出過付款也不能再付。
     */
    public function isPayable(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture() && $this->payment_submitted_at === null && ! $this->failedForPlayer();
    }

    /**
     * 標記這張訂單已送出付款。同時送出時只有一方會成功；狀態與期限在同一個 UPDATE 裡重新檢查，
     * 剛好在檢查後才逾期或被改狀態的訂單不會送出付款。
     */
    public function claimPayment(): bool
    {
        $claimed = self::whereKey($this->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->whereNull('payment_submitted_at')
            ->update(['payment_submitted_at' => now()]);

        if ($claimed === 1) {
            $this->refresh();
        }

        return $claimed === 1;
    }

    /** 和 failedForPlayer 相同的判定，給後台的列表篩選。 */
    public function scopeFailedForPlayer(Builder $query): Builder
    {
        return $query->where('status', '!=', 'fulfilled')->where(fn (Builder $q) => $q
            ->where('status', 'failed')
            ->orWhere(fn (Builder $expired) => $expired->where('status', 'expired')->whereNotNull('payment_submitted_at'))
            ->orWhereHas('grant', fn (Builder $grant) => $grant->where('status', 'failed'))
            ->orWhereHas('payments', fn (Builder $payment) => $payment->where('status', 'amount_mismatch')));
    }
}
