<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 遊戲內充值送出付款資料的時間。只接受一次，避免重複付款；
     * 逾期的遊戲內訂單只有送出過付款才算失敗，沒付款就關掉彈窗的只是過期。
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('payment_submitted_at')->nullable()->after('payment_method');
        });
        // 之前的遊戲內充值在建立訂單時就自動付款。
        DB::table('orders')->where('source', 'game')->update(['payment_submitted_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_submitted_at');
        });
    }
};
