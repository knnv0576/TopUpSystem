<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 充值端資料表。金額是新台幣整數元，點數是整數。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_player_id')->unique();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspend_reason', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 32)->unique();
            $table->string('password');
            $table->string('role', 16);
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->bigInteger('totp_last_timestamp')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE admin_users ADD CONSTRAINT admin_users_role CHECK (role IN ('reviewer','supervisor'))");

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->integer('price');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE packages ADD CONSTRAINT packages_price_positive CHECK (price > 0)');

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 32)->unique();
            $table->foreignId('player_id')->constrained('players');
            $table->foreignId('package_id')->constrained('packages');
            $table->string('package_name', 50);
            $table->integer('amount');
            $table->bigInteger('points');
            $table->string('currency', 3)->default('TWD');
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['player_id', 'id']);
            $table->index(['status', 'expires_at']);
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status CHECK (status IN ('pending','paid','fulfilled','expired','failed'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_positive CHECK (amount > 0 AND points > 0)');

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->string('provider', 16);
            $table->string('provider_txn_id', 64);
            $table->integer('amount');
            $table->string('currency', 3);
            $table->string('status', 24);
            $table->timestamps();
            $table->unique(['provider', 'provider_txn_id']);
        });

        Schema::create('gateway_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);
            $table->string('event_id', 64);
            $table->string('type', 32);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('point_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders');
            $table->foreignId('player_id')->constrained('players');
            $table->bigInteger('points');
            $table->string('status', 16)->default('pending');
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });
        DB::statement("ALTER TABLE point_grants ADD CONSTRAINT point_grants_status CHECK (status IN ('pending','granted','failed','held_suspended'))");

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('game_request_id')->unique();
            $table->foreignId('player_id')->constrained('players');
            $table->bigInteger('points');
            $table->bigInteger('amount');
            $table->string('status', 24)->default('pending_freeze');
            $table->string('bank_code', 3);
            $table->string('account_last4', 4);
            $table->text('account_encrypted')->nullable();
            $table->string('reject_reason', 200)->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('admin_users');
            $table->foreignId('supervisor_id')->nullable()->constrained('admin_users');
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
            $table->index(['player_id', 'id']);
        });
        DB::statement("ALTER TABLE refund_requests ADD CONSTRAINT refund_requests_status CHECK (status IN ('pending_freeze','pending_review','pending_supervisor','approved','points_deducted','paying_out','payout_failed','closing','paid_out','rejected','closed_points_returned'))");
        DB::statement('ALTER TABLE refund_requests ADD CONSTRAINT refund_requests_two_people CHECK (reviewer_id IS NULL OR supervisor_id IS NULL OR reviewer_id <> supervisor_id)');
        DB::statement('ALTER TABLE refund_requests ADD CONSTRAINT refund_requests_points_rule CHECK (points >= 10000 AND points % 100 = 0 AND amount = points / 100)');
        // 每位玩家同時只能有一筆進行中的申請。
        DB::statement("CREATE UNIQUE INDEX refund_requests_one_open_per_player ON refund_requests (player_id) WHERE status NOT IN ('paid_out','rejected','closed_points_returned')");

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_request_id')->unique()->constrained('refund_requests');
            $table->bigInteger('amount');
            $table->string('idempotency_key', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->string('provider_txn_id', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });

        Schema::create('point_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players');
            $table->string('direction', 8);
            $table->bigInteger('points');
            $table->string('reason', 200);
            $table->string('status', 24)->default('pending_supervisor');
            $table->string('reject_reason', 200)->nullable();
            $table->foreignId('initiator_id')->constrained('admin_users');
            $table->foreignId('supervisor_id')->nullable()->constrained('admin_users');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_direction CHECK (direction IN ('add','deduct'))");
        DB::statement("ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_status CHECK (status IN ('pending_supervisor','applied','rejected'))");
        DB::statement('ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_points_positive CHECK (points > 0)');
        DB::statement('ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_two_people CHECK (supervisor_id IS NULL OR initiator_id <> supervisor_id)');

        Schema::create('anomalies', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('detail', 500);
            $table->foreignId('resolved_by')->nullable()->constrained('admin_users');
            $table->string('resolution_note', 200)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['resolved_at', 'id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('sso_tokens', function (Blueprint $table) {
            $table->string('jti', 64)->primary();
            $table->unsignedBigInteger('game_player_id');
            $table->timestamp('used_at')->useCurrent();
            $table->timestamp('expires_at')->index();
        });

        Schema::create('api_nonces', function (Blueprint $table) {
            $table->string('nonce', 64)->primary();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 16);
            $table->string('actor', 64)->nullable();
            $table->string('action', 48);
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->bigInteger('amount')->nullable();
            $table->bigInteger('points')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
        // 稽核紀錄只能新增：資料庫層拒絕逐列修改與刪除。
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER audit_logs_no_change BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();
        SQL);

        // 模擬金流自己的撥款帳，用來證明同一個冪等鍵不會付款兩次。
        Schema::create('mock_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 64)->unique();
            $table->string('provider_txn_id', 64)->unique();
            $table->bigInteger('amount');
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['mock_payouts', 'audit_logs', 'api_nonces', 'sso_tokens', 'anomalies', 'point_adjustments', 'payouts', 'refund_requests', 'point_grants', 'gateway_events', 'payments', 'orders', 'packages', 'admin_users', 'players'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_append_only()');
    }
};
