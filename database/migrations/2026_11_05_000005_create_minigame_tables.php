<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minigame_rounds', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('game_player_id')->constrained('game_players');
            $table->string('player_no', 12);
            $table->string('status', 20)->default('committed');
            $table->text('server_seed');
            $table->char('server_seed_hash', 64);
            $table->string('client_seed', 64)->nullable();
            $table->bigInteger('bet_points')->nullable();
            $table->smallInteger('roll')->nullable();
            $table->integer('multiplier_pct')->nullable();
            $table->bigInteger('payout_points')->nullable();
            $table->char('integrity_mac', 64)->nullable();
            $table->string('reject_reason', 32)->nullable();
            $table->timestamp('spun_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
            $table->index(['game_player_id', 'id']);
        });

        DB::statement("ALTER TABLE minigame_rounds ADD CONSTRAINT minigame_rounds_status CHECK (status IN ('committed','debit_pending','payout_pending','settled','rejected','integrity_hold'))");
        DB::statement('ALTER TABLE minigame_rounds ADD CONSTRAINT minigame_rounds_values CHECK (bet_points > 0 AND payout_points >= 0 AND roll BETWEEN 0 AND 99 AND multiplier_pct >= 0)');
        DB::statement("ALTER TABLE minigame_rounds ADD CONSTRAINT minigame_rounds_spun_complete CHECK (status IN ('committed','integrity_hold') OR (client_seed IS NOT NULL AND bet_points IS NOT NULL AND roll IS NOT NULL AND multiplier_pct IS NOT NULL AND payout_points IS NOT NULL AND integrity_mac IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX minigame_rounds_one_committed ON minigame_rounds (game_player_id) WHERE status = 'committed'");

        // 開局後局資料不能改、狀態只能往前、不能刪除單筆。TRUNCATE 不受影響，測試面板的重置才能運作。
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION minigame_rounds_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'minigame_rounds rows cannot be deleted';
                END IF;
                IF OLD.status IN ('settled', 'rejected', 'integrity_hold') THEN
                    RAISE EXCEPTION 'finished minigame round is immutable';
                END IF;
                IF (NEW.public_id, NEW.game_player_id, NEW.player_no, NEW.server_seed, NEW.server_seed_hash, NEW.created_at)
                    IS DISTINCT FROM (OLD.public_id, OLD.game_player_id, OLD.player_no, OLD.server_seed, OLD.server_seed_hash, OLD.created_at) THEN
                    RAISE EXCEPTION 'minigame round identity is immutable';
                END IF;
                IF OLD.status <> 'committed'
                    AND (NEW.client_seed, NEW.bet_points, NEW.roll, NEW.multiplier_pct, NEW.payout_points, NEW.integrity_mac, NEW.spun_at)
                    IS DISTINCT FROM (OLD.client_seed, OLD.bet_points, OLD.roll, OLD.multiplier_pct, OLD.payout_points, OLD.integrity_mac, OLD.spun_at) THEN
                    RAISE EXCEPTION 'minigame round outcome is immutable';
                END IF;
                IF NOT (
                    (OLD.status = 'committed' AND NEW.status IN ('debit_pending', 'integrity_hold'))
                    OR (OLD.status = 'debit_pending' AND NEW.status IN ('debit_pending', 'payout_pending', 'rejected', 'integrity_hold'))
                    OR (OLD.status = 'payout_pending' AND NEW.status IN ('payout_pending', 'settled', 'integrity_hold'))
                ) THEN
                    RAISE EXCEPTION 'invalid minigame round transition: % -> %', OLD.status, NEW.status;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER minigame_rounds_guard BEFORE UPDATE OR DELETE ON minigame_rounds
                FOR EACH ROW EXECUTE FUNCTION minigame_rounds_guard();
        SQL);

        Schema::create('minigame_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('wheel_enabled')->default(true);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE minigame_settings ADD CONSTRAINT minigame_settings_single_row CHECK (id = 1)');
        DB::table('minigame_settings')->insert(['id' => 1, 'wheel_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('minigame_settings');
        Schema::dropIfExists('minigame_rounds');
        DB::unprepared('DROP FUNCTION IF EXISTS minigame_rounds_guard()');
    }
};
