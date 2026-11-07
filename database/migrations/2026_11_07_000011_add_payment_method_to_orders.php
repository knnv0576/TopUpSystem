<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 玩家在選方案時決定的付款方式；付款頁只顯示這一種。舊訂單與遊戲內充值為 null。 */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 8)->nullable()->after('currency');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_method CHECK (payment_method IN ('card','atm','cvs'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_method');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
