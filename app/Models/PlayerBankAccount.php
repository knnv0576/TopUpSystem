<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 玩家綁定的撥款帳戶。帳號只以密文保存，畫面只顯示遮罩。 */
class PlayerBankAccount extends Model
{
    public const MAX_PER_PLAYER = 5;

    protected $guarded = [];

    protected $hidden = ['account_encrypted', 'account_fingerprint', 'holder_name_encrypted'];

    protected $casts = ['account_encrypted' => 'encrypted', 'holder_name_encrypted' => 'encrypted', 'verified_at' => 'datetime', 'returned_at' => 'datetime'];

    /** 戶名遮罩：保留第一個與最後一個字，例如「王*明」「T**T USER」以字為單位。 */
    public static function maskName(string $name): string
    {
        $chars = mb_str_split($name);
        $n = count($chars);
        if ($n <= 1) {
            return '*';
        }
        if ($n === 2) {
            return $chars[0].'*';
        }

        return $chars[0].str_repeat('*', $n - 2).$chars[$n - 1];
    }

    /** 可以選來退款：曾經退匯的帳戶要刪除後重新綁定正確的帳戶。 */
    public function usable(): bool
    {
        return $this->returned_at === null;
    }

    public static function fingerprint(string $bankCode, string $account): string
    {
        $key = hash_hkdf('sha256', (string) config('app.key'), 32, 'bank-account-fingerprint');

        return hash_hmac('sha256', "$bankCode:$account", $key);
    }

    public function masked(): string
    {
        return $this->bank_code.'-****'.$this->account_last4;
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'bank_code' => $this->bank_code,
            'masked' => $this->masked(),
            'holder' => $this->holder_name_masked,
            'verified' => $this->verified_at !== null,
            'returned' => $this->returned_at !== null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
