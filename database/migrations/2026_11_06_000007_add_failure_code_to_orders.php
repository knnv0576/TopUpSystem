<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 金流通知帶來的失敗原因代碼，例如 card_declined；只存代碼，不存卡片資料。 */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('failure_code', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('failure_code');
        });
    }
};
