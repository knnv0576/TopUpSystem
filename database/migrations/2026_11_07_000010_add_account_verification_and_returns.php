<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD = "'pending_freeze','pending_review','pending_supervisor','approved','points_deducted','paying_out','payout_failed','closing','paid_out','rejected','closed_points_returned'";

    /**
     * 戶名驗證：綁定時向（模擬）銀行確認帳號存在且戶名相符。戶名加密保存，畫面只顯示遮罩。
     * 退匯：撥款成功後銀行又把款項退回（例如帳戶已結清），申請進入 payout_returned。
     */
    public function up(): void
    {
        Schema::table('player_bank_accounts', function (Blueprint $table) {
            $table->text('holder_name_encrypted')->nullable()->after('account_fingerprint');
            $table->string('holder_name_masked', 60)->nullable()->after('holder_name_encrypted');
            $table->timestamp('verified_at')->nullable()->after('holder_name_masked');
            $table->timestamp('returned_at')->nullable()->after('verified_at');
        });
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->string('holder_name_masked', 60)->nullable()->after('bank_label');
        });

        DB::statement('ALTER TABLE refund_requests DROP CONSTRAINT refund_requests_status');
        DB::statement('ALTER TABLE refund_requests ADD CONSTRAINT refund_requests_status CHECK (status IN ('.self::OLD.",'payout_returned'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refund_requests DROP CONSTRAINT refund_requests_status');
        DB::statement('ALTER TABLE refund_requests ADD CONSTRAINT refund_requests_status CHECK (status IN ('.self::OLD.'))');
        Schema::table('refund_requests', fn (Blueprint $table) => $table->dropColumn('holder_name_masked'));
        Schema::table('player_bank_accounts', fn (Blueprint $table) => $table->dropColumn(['holder_name_encrypted', 'holder_name_masked', 'verified_at', 'returned_at']));
    }
};
