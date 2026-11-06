<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 玩家端資料表。與充值端隔離，兩邊只透過 HTTP API 溝通。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->string('username', 32)->unique();
            $table->string('password');
            $table->string('nickname', 32);
            $table->string('player_no', 12)->unique();
            $table->timestamp('suspended_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('game_wallets', function (Blueprint $table) {
            $table->foreignId('player_id')->primary()->constrained('game_players');
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('frozen')->default(0);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE game_wallets ADD CONSTRAINT game_wallets_frozen_range CHECK (frozen >= 0 AND frozen <= balance)');

        Schema::create('game_point_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('game_players');
            $table->string('type', 32);
            $table->bigInteger('delta_balance');
            $table->bigInteger('delta_frozen');
            $table->bigInteger('balance_after');
            $table->bigInteger('frozen_after');
            $table->string('idempotency_key', 120)->unique();
            $table->string('memo', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['player_id', 'id']);
        });

        Schema::create('game_red_packets', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('sender_id')->constrained('game_players');
            $table->foreignId('recipient_id')->constrained('game_players');
            $table->bigInteger('points');
            $table->string('message', 100)->nullable();
            $table->string('status', 16)->default('sent');
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['recipient_id', 'id']);
            $table->index(['sender_id', 'id']);
        });
        DB::statement('ALTER TABLE game_red_packets ADD CONSTRAINT game_red_packets_not_self CHECK (sender_id <> recipient_id)');
        DB::statement('ALTER TABLE game_red_packets ADD CONSTRAINT game_red_packets_points_positive CHECK (points > 0)');
        DB::statement("ALTER TABLE game_red_packets ADD CONSTRAINT game_red_packets_status CHECK (status IN ('sent','claimed','expired','returned'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('game_red_packets');
        Schema::dropIfExists('game_point_ledger');
        Schema::dropIfExists('game_wallets');
        Schema::dropIfExists('game_players');
    }
};
