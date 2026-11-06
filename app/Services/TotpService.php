<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

class TotpService
{
    public function __construct(private Google2FA $totp) {}

    public function generateSecret(): string
    {
        return $this->totp->generateSecretKey(32);
    }

    public function provisioningUri(AdminUser $admin, string $secret): string
    {
        return $this->totp->getQRCodeUrl('TopUp DEMO', $admin->username, $secret);
    }

    /** 驗證碼只能用一次：同一個時間步或更舊的代碼會被拒絕，防止重放。 */
    public function verify(AdminUser $admin, ?string $code): bool
    {
        // 鎖定或停用時，即使驗證碼正確也不接受。
        if (! $admin->fresh()->canAuthenticate()) {
            AuditLog::record('admin.totp_failed', $admin, ['note' => '帳號已鎖定或停用'], $admin);

            return false;
        }

        if ($this->check($admin, $code)) {
            $admin->fresh()->clearFailures();

            return true;
        }

        AuditLog::record('admin.totp_failed', $admin, [], $admin);
        if ($admin->fresh()->registerFailure()) {
            AuditLog::record('admin.locked', $admin, ['note' => '連續驗證失敗，帳號暫時鎖定'], $admin);
        }

        return false;
    }

    private function check(AdminUser $admin, ?string $code): bool
    {
        if ($code === null || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return DB::transaction(function () use ($admin, $code) {
            $locked = AdminUser::whereKey($admin->id)->lockForUpdate()->first();
            if (! $locked->totp_secret) {
                return false;
            }

            // 舊時間步必須是數字：傳 null 時程式庫只回傳 true，拿不到可記錄的時間步。
            $timestamp = $this->totp->verifyKeyNewer($locked->totp_secret, $code, (int) $locked->totp_last_timestamp, 1);
            if (! is_int($timestamp)) {
                return false;
            }

            $locked->update(['totp_last_timestamp' => $timestamp]);

            return true;
        });
    }
}
