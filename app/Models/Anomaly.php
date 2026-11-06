<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 後台異常清單：需要人工判斷或重試的案件。 */
class Anomaly extends Model
{
    public const KIND_LABELS = [
        'amount_mismatch' => '付款金額不符',
        'grant_failed' => '入點失敗',
        'grant_held_suspended' => '玩家停權，暫不入點',
        'payout_failed' => '撥款失敗',
        'payout_returned' => '撥款退匯',
        'freeze_failed' => '凍結點數失敗',
        'unfreeze_failed' => '解凍點數失敗',
        'suspension_sync_failed' => '停權同步失敗',
        'late_payout_result' => '撥款結果晚於結案',
    ];

    protected $guarded = [];

    protected $casts = ['resolved_at' => 'datetime'];

    public static function raise(string $kind, string $subjectType, int $subjectId, string $detail): self
    {
        return self::firstOrCreate(
            ['kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'resolved_at' => null],
            ['detail' => mb_substr($detail, 0, 500)],
        );
    }

    /** 系統自動處理完成時關閉相關異常。 */
    public static function resolveFor(string $subjectType, int $subjectId, string $note, ?int $adminId = null, ?string $kind = null): void
    {
        self::where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->whereNull('resolved_at')
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->update(['resolved_at' => now(), 'resolved_by' => $adminId, 'resolution_note' => $note, 'updated_at' => now()]);
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }
}
