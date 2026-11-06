<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 帳號管理、登入鎖定與稽核紀錄保護。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::table('game_players', function (Blueprint $table) {
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('ip', 45)->nullable();
        });

        // 逐列修改與刪除已被擋下；TRUNCATE 是敘述層級操作，要另外擋。
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('ip');
        });
        Schema::table('game_players', function (Blueprint $table) {
            $table->dropColumn(['failed_attempts', 'locked_until']);
        });
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at', 'disabled_at', 'failed_attempts', 'locked_until', 'last_login_at']);
        });
    }
};
