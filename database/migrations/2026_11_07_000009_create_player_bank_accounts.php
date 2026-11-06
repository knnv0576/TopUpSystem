<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 玩家綁定的撥款帳戶（充值端）。帳號以 APP_KEY 加密，畫面只顯示銀行代碼與後四碼；
     * fingerprint 是 HMAC，用來擋同一個帳戶重複綁定而不用解密比對。
     * 退款申請送出時會複製一份帳戶資料，之後刪除綁定不影響進行中的撥款。
     */
    public function up(): void
    {
        Schema::create('player_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players');
            $table->string('label', 30);
            $table->string('bank_code', 3);
            $table->string('account_last4', 4);
            $table->text('account_encrypted');
            $table->char('account_fingerprint', 64);
            $table->timestamps();
            $table->unique(['player_id', 'account_fingerprint']);
        });

        Schema::table('refund_requests', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('account_encrypted')->constrained('player_bank_accounts')->nullOnDelete();
            $table->string('bank_label', 30)->nullable()->after('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn('bank_label');
        });
        Schema::dropIfExists('player_bank_accounts');
    }
};
