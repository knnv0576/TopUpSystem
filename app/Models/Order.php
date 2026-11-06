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

    protected $guarded = [];

    protected $casts = ['amount' => 'integer', 'points' => 'integer', 'expires_at' => 'datetime'];

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
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** 玩家看到的失敗不分原因；實際狀態只在後台顯示。 */
    public function playerStatusLabel(): string
    {
        return $this->failedForPlayer() ? PlayerMessages::TOPUP_FAILED : $this->statusLabel();
    }

    public function failedForPlayer(): bool
    {
        if ($this->status === 'fulfilled') {
            return false;
        }

        return $this->status === 'failed'
            || ($this->status === 'expired' && $this->source === 'game')
            || $this->grant()->where('status', 'failed')->exists()
            || $this->payments()->where('status', 'amount_mismatch')->exists();
    }

    /** 和 failedForPlayer 相同的判定，給後台的列表篩選。 */
    public function scopeFailedForPlayer(Builder $query): Builder
    {
        return $query->where('status', '!=', 'fulfilled')->where(fn (Builder $q) => $q
            ->where('status', 'failed')
            ->orWhere(fn (Builder $expired) => $expired->where('status', 'expired')->where('source', 'game'))
            ->orWhereHas('grant', fn (Builder $grant) => $grant->where('status', 'failed'))
            ->orWhereHas('payments', fn (Builder $payment) => $payment->where('status', 'amount_mismatch')));
    }
}
