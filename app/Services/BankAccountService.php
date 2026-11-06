<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Player;
use App\Models\PlayerBankAccount;
use App\Payments\PaymentGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 玩家的撥款帳戶（充值端）。新增與刪除都寫入稽核紀錄，紀錄裡只有遮罩後的帳號。 */
class BankAccountService
{
    public function __construct(private PaymentGateway $gateway) {}

    /** @return \Illuminate\Support\Collection<int, PlayerBankAccount> */
    public function list(Player $player)
    {
        return PlayerBankAccount::where('player_id', $player->id)->orderBy('id')->get();
    }

    public function add(Player $player, string $label, string $bankCode, string $account, string $holderName): PlayerBankAccount
    {
        if ($player->isSuspended()) {
            throw new RuntimeException(\App\Support\PlayerMessages::SUSPENDED);
        }

        // 綁定前先向銀行確認帳號存在、戶名相符（這裡是模擬銀行）。
        match ($this->gateway->verifyAccount($bankCode, $account, $holderName)) {
            'matched' => null,
            'account_not_found' => throw new RuntimeException('銀行查無這個帳號，請確認銀行代碼與帳號'),
            'name_mismatch' => throw new RuntimeException('戶名與銀行資料不符，請確認戶名'),
        };

        try {
            return DB::transaction(function () use ($player, $label, $bankCode, $account, $holderName) {
                // 鎖定玩家列，同時新增時數量上限才準確。
                Player::whereKey($player->id)->lockForUpdate()->first();
                if (PlayerBankAccount::where('player_id', $player->id)->count() >= PlayerBankAccount::MAX_PER_PLAYER) {
                    throw new RuntimeException('最多只能綁定 '.PlayerBankAccount::MAX_PER_PLAYER.' 個撥款帳戶');
                }

                $bankAccount = PlayerBankAccount::create([
                    'player_id' => $player->id,
                    'label' => $label,
                    'bank_code' => $bankCode,
                    'account_last4' => substr($account, -4),
                    'account_encrypted' => $account,
                    'account_fingerprint' => PlayerBankAccount::fingerprint($bankCode, $account),
                    'holder_name_encrypted' => $holderName,
                    'holder_name_masked' => PlayerBankAccount::maskName($holderName),
                    'verified_at' => now(),
                ]);
                AuditLog::record('bank_account.added', $player, ['actor_type' => 'player', 'actor' => (string) $player->game_player_id, 'note' => "{$bankAccount->masked()}（{$label}，戶名 {$bankAccount->holder_name_masked}，已驗證）"]);

                return $bankAccount;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('這個帳戶已經綁定過了');
        }
    }

    public function remove(Player $player, int $id): void
    {
        $bankAccount = PlayerBankAccount::where('player_id', $player->id)->findOrFail($id);
        DB::transaction(function () use ($player, $bankAccount) {
            $bankAccount->delete();
            AuditLog::record('bank_account.removed', $player, ['actor_type' => 'player', 'actor' => (string) $player->game_player_id, 'note' => "{$bankAccount->masked()}（{$bankAccount->label}）"]);
        });
    }
}
