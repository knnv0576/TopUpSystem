<?php

namespace App\DemoGame\Services;

use App\DemoGame\Exceptions\DebitVoided;
use App\DemoGame\Exceptions\InsufficientPoints;
use App\DemoGame\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 點數帳本。所有餘額異動都經過這裡：鎖定錢包、以冪等鍵去重，並與帳本同交易寫入。
 */
class WalletService
{
    public function credit(int $playerId, int $points, string $type, string $key, ?string $memo = null): void
    {
        $this->assertPositive($points);

        $this->apply($playerId, $key, function (Wallet $wallet) use ($points, $type, $memo, $playerId, $key) {
            if ($wallet->balance > PHP_INT_MAX - $points) {
                throw new RuntimeException('點數超過可儲存範圍');
            }
            $this->write($wallet, $playerId, $type, $points, 0, $key, $memo);
        });
    }

    /** 只能扣可用餘額（餘額 - 凍結）。已作廢的鍵不再扣點。 */
    public function debit(int $playerId, int $points, string $type, string $key, ?string $memo = null): void
    {
        $this->assertPositive($points);

        $this->apply($playerId, $key, function (Wallet $wallet) use ($points, $type, $memo, $playerId, $key) {
            if ($this->entryExists("void:$key")) {
                throw new DebitVoided;
            }
            if ($wallet->balance - $wallet->frozen < $points) {
                throw new InsufficientPoints;
            }
            $this->write($wallet, $playerId, $type, -$points, 0, $key, $memo);
        });
    }

    /**
     * 作廢一筆還沒發生的扣點：留下零異動紀錄，之後晚到的同鍵扣點不會生效。
     * 與扣點鎖定同一個錢包，兩者只會有一個先發生。
     *
     * @return bool 已作廢回傳 true；扣點已經發生時不作廢，回傳 false
     */
    public function voidDebit(int $playerId, string $key): bool
    {
        return DB::transaction(function () use ($playerId, $key) {
            $wallet = Wallet::whereKey($playerId)->lockForUpdate()->firstOrFail();

            if ($this->entryExists($key)) {
                return false;
            }
            if (! $this->entryExists("void:$key")) {
                $this->write($wallet, $playerId, 'game_debit_void', 0, 0, "void:$key", '扣點作廢');
            }

            return true;
        });
    }

    /**
     * 凍結。如果對應的解凍已先發生（停權與凍結工作競爭時），就不再凍結，避免點數被永久卡住。
     */
    public function freeze(int $playerId, int $points, string $ref): void
    {
        $this->assertPositive($points);
        $key = "$ref:freeze";

        $this->apply($playerId, $key, function (Wallet $wallet) use ($points, $playerId, $key, $ref) {
            if ($this->entryExists("$ref:unfreeze")) {
                return;
            }
            if ($wallet->balance - $wallet->frozen < $points) {
                throw new InsufficientPoints;
            }
            $this->write($wallet, $playerId, 'refund_freeze', 0, $points, $key, '退款申請凍結');
        });
    }

    /** 解凍。尚未凍結時只留下零異動紀錄，讓之後晚到的凍結變成無作用。 */
    public function unfreeze(int $playerId, int $points, string $ref): void
    {
        $this->assertPositive($points);
        $key = "$ref:unfreeze";

        $this->apply($playerId, $key, function (Wallet $wallet) use ($points, $playerId, $key, $ref) {
            $delta = $this->entryExists("$ref:freeze") ? -$points : 0;
            $this->write($wallet, $playerId, 'refund_unfreeze', 0, $delta, $key, '退款申請解凍');
        });
    }

    public function deductFrozen(int $playerId, int $points, string $ref): void
    {
        $this->assertPositive($points);
        $key = "$ref:deduct";

        $this->apply($playerId, $key, function (Wallet $wallet) use ($points, $playerId, $key, $ref) {
            if (! $this->entryExists("$ref:freeze") || $this->entryExists("$ref:return") || $wallet->frozen < $points) {
                throw new RuntimeException('沒有可扣除的凍結點數');
            }
            $this->write($wallet, $playerId, 'refund_deduct', -$points, -$points, $key, '退款核准扣點');
        });
    }

    public function returnDeducted(int $playerId, int $points, string $ref): void
    {
        $this->assertPositive($points);
        $key = "$ref:return";

        $this->apply($playerId, $key, function (Wallet $wallet) use ($points, $playerId, $key, $ref) {
            if ($this->entryExists("$ref:deduct")) {
                $this->write($wallet, $playerId, 'refund_return', $points, 0, $key, '撥款失敗結案還點');
            } elseif ($this->entryExists("$ref:freeze")) {
                // 還沒扣點就失敗：點數仍在凍結中，解凍即可。
                $this->write($wallet, $playerId, 'refund_return', 0, -$points, $key, '撥款失敗結案解凍');
            } else {
                $this->write($wallet, $playerId, 'refund_return', 0, 0, $key, '撥款失敗結案');
            }
        });
    }

    /** 鎖定錢包後才檢查冪等鍵，同一玩家的異動會排隊執行。 */
    private function apply(int $playerId, string $key, callable $change): void
    {
        DB::transaction(function () use ($playerId, $key, $change) {
            $wallet = Wallet::whereKey($playerId)->lockForUpdate()->firstOrFail();

            if (! $this->entryExists($key)) {
                $change($wallet);
            }
        });
    }

    private function entryExists(string $key): bool
    {
        return DB::table('game_point_ledger')->where('idempotency_key', $key)->exists();
    }

    private function write(Wallet $wallet, int $playerId, string $type, int $deltaBalance, int $deltaFrozen, string $key, ?string $memo): void
    {
        $wallet->balance += $deltaBalance;
        $wallet->frozen += $deltaFrozen;
        $wallet->save();

        DB::table('game_point_ledger')->insert([
            'player_id' => $playerId,
            'type' => $type,
            'delta_balance' => $deltaBalance,
            'delta_frozen' => $deltaFrozen,
            'balance_after' => $wallet->balance,
            'frozen_after' => $wallet->frozen,
            'idempotency_key' => $key,
            'memo' => $memo,
            'created_at' => now(),
        ]);
    }

    private function assertPositive(int $points): void
    {
        if ($points <= 0) {
            throw new RuntimeException('點數必須是正整數');
        }
    }
}
