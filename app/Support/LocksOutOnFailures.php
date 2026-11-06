<?php

namespace App\Support;

/**
 * 帳號層級的失敗鎖定。路由限流只看來源 IP，這裡防的是針對單一帳號的猜測。
 */
trait LocksOutOnFailures
{
    public function isLockedOut(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /** 記錄一次失敗；這次剛好觸發鎖定時回傳 true。 */
    public function registerFailure(): bool
    {
        static::whereKey($this->getKey())->increment('failed_attempts');
        $this->refresh();

        if ($this->failed_attempts < config('topup.lockout_attempts')) {
            return false;
        }

        $this->forceFill([
            'failed_attempts' => 0,
            'locked_until' => now()->addMinutes(config('topup.lockout_minutes')),
        ])->save();

        return true;
    }

    public function clearFailures(): void
    {
        if ($this->failed_attempts !== 0 || $this->locked_until !== null) {
            $this->forceFill(['failed_attempts' => 0, 'locked_until' => null])->save();
        }
    }
}
