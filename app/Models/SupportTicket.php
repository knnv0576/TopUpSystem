<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 客服工單。內容只能新增訊息，不能修改或刪除既有訊息。 */
class SupportTicket extends Model
{
    public const CATEGORIES = [
        'topup' => '充值問題',
        'refund' => '退款問題',
        'game' => '小遊戲',
        'account' => '帳號問題',
        'other' => '其他',
    ];

    public const STATUS_LABELS = [
        'open' => '待處理',
        'answered' => '已回覆',
        'closed' => '已結案',
    ];

    protected $guarded = [];

    protected $casts = ['last_message_at' => 'datetime', 'closed_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'ticket_no';
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('id');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
