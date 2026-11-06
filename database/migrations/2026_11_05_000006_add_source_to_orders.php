<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 8)->default('shop');
            $table->uuid('request_id')->nullable();
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_source CHECK (source IN ('shop','game'))");
        // 重複點擊只建立一張訂單。
        DB::statement('CREATE UNIQUE INDEX orders_player_request ON orders (player_id, request_id) WHERE request_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_player_request');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_source');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['source', 'request_id']);
        });
    }
};
