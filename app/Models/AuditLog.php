<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 只新增不修改；資料庫觸發器也會拒絕逐列更新與刪除。不得寫入完整帳號。 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public static function record(string $action, ?Model $subject = null, array $data = [], ?AdminUser $admin = null): self
    {
        return self::create([
            'actor_type' => $admin ? 'admin' : ($data['actor_type'] ?? 'system'),
            'actor' => $admin?->username ?? ($data['actor'] ?? null),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'from_status' => $data['from'] ?? null,
            'to_status' => $data['to'] ?? null,
            'amount' => $data['amount'] ?? null,
            'points' => $data['points'] ?? null,
            'note' => isset($data['note']) ? mb_substr($data['note'], 0, 300) : null,
            // 只記錄連線來源；排程與佇列工作沒有來源 IP。
            'ip' => request()->server('REMOTE_ADDR'),
        ]);
    }
}
