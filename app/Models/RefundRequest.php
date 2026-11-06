<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RefundRequest extends Model
{
    /** 審核中：停權時會被自動拒絕。 */
    public const IN_REVIEW = ['pending_freeze', 'pending_review', 'pending_supervisor'];

    public const CLOSED = ['paid_out', 'rejected', 'closed_points_returned'];

    public const ADMIN_LABELS = [
        'pending_freeze' => '凍結中',
        'pending_review' => '待初審',
        'pending_supervisor' => '待覆核',
        'approved' => '已核准',
        'points_deducted' => '已扣點',
        'paying_out' => '撥款中',
        'payout_failed' => '撥款失敗',
        'payout_returned' => '撥款遭退匯',
        'closing' => '結案還點中',
        'paid_out' => '已撥款',
        'rejected' => '已拒絕',
        'closed_points_returned' => '已結案並還點',
    ];

    /** 玩家看到的進度：不顯示審核層級與管理員身分。 */
    public const PLAYER_LABELS = [
        'pending_freeze' => '審核中',
        'pending_review' => '審核中',
        'pending_supervisor' => '審核中',
        'approved' => '撥款中',
        'points_deducted' => '撥款中',
        'paying_out' => '撥款中',
        'payout_failed' => '撥款中',
        'payout_returned' => '撥款被銀行退回，處理中',
        'closing' => '撥款中',
        'paid_out' => '已完成',
        'rejected' => '已拒絕',
        'closed_points_returned' => '已結案並還點',
    ];

    protected $guarded = [];

    protected $hidden = ['account_encrypted'];

    protected $casts = [
        'points' => 'integer',
        'amount' => 'integer',
        'account_encrypted' => 'encrypted',
        'review_started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewer_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'supervisor_id');
    }

    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class);
    }

    /** 玩家端點數異動的參考編號。 */
    public function ref(): string
    {
        return "refund:{$this->id}";
    }

    public function maskedAccount(): string
    {
        return $this->bank_code.'-****'.$this->account_last4;
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['pending_review', 'pending_supervisor'], true)
            && $this->review_started_at !== null
            && $this->review_started_at->lte(now()->subHours(config('topup.review_overdue_hours')));
    }

    public function adminLabel(): string
    {
        return self::ADMIN_LABELS[$this->status] ?? $this->status;
    }
}
