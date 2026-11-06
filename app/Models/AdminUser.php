<?php

namespace App\Models;

use App\Support\LocksOutOnFailures;
use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable
{
    use LocksOutOnFailures;

    public const REVIEWER = 'reviewer';

    public const SUPERVISOR = 'supervisor';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'totp_secret'];

    protected $casts = [
        'password' => 'hashed',
        'totp_secret' => 'encrypted',
        'totp_confirmed_at' => 'datetime',
        'must_change_password' => 'boolean',
        'password_changed_at' => 'datetime',
        'disabled_at' => 'datetime',
        'failed_attempts' => 'integer',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function isSupervisor(): bool
    {
        return $this->role === self::SUPERVISOR;
    }

    public function isReviewer(): bool
    {
        return $this->role === self::REVIEWER;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** 帳號目前是否可以使用（未停用、未鎖定）。 */
    public function canAuthenticate(): bool
    {
        return ! $this->isDisabled() && ! $this->isLockedOut();
    }

    /**
     * 登入憑證的指紋，存在 session 裡。密碼或 TOTP 綁定改變後指紋就不同，
     * 用舊憑證登入的 session 會全部失效。
     */
    public function sessionFingerprint(): string
    {
        return hash_hmac('sha256', $this->password.'|'.$this->totp_confirmed_at?->getTimestamp(), (string) config('app.key'));
    }

    public function fingerprintSessionKey(): string
    {
        return 'admin_credential_fingerprint_'.$this->getKey();
    }

    public function roleLabel(): string
    {
        return $this->isSupervisor() ? '覆核' : '初審';
    }
}
