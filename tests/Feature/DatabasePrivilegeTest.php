<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 應用程式的資料庫帳號只有讀寫權限：改不了結構、觸發器與稽核紀錄，也不能執行系統指令。 */
class DatabasePrivilegeTest extends TestCase
{
    private function appConnection(): Connection
    {
        return DB::connection('pgsql');
    }

    public function test_app_account_is_not_owner_or_superuser(): void
    {
        $role = $this->appConnection()->selectOne('select rolname, rolsuper, rolcreaterole, rolcreatedb, rolbypassrls from pg_roles where rolname = current_user');

        $this->assertNotSame(config('database.connections.pgsql_owner.username'), $role->rolname);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolcreaterole);
        $this->assertFalse($role->rolcreatedb);
        $this->assertFalse($role->rolbypassrls);
        $this->assertSame(0, (int) $this->appConnection()->selectOne("select count(*) as n from pg_tables where schemaname = 'public' and tableowner = current_user")->n);
    }

    public function test_app_account_can_read_and_write_data(): void
    {
        $this->assertIsInt((int) $this->appConnection()->selectOne('select count(*) as n from orders')->n);
        $this->appConnection()->table('api_nonces')->insert(['nonce' => 'privilege-test-'.uniqid(), 'created_at' => now()]);
        $this->assertSame(1, $this->appConnection()->table('api_nonces')->where('nonce', 'like', 'privilege-test-%')->delete());
    }

    /** 後台「重置測試資料」用應用程式帳號執行 TRUNCATE ... CASCADE，不能因為權限不足而失敗。 */
    public function test_app_account_can_run_demo_reset_truncate(): void
    {
        $tables = (new \ReflectionClassConstant(\App\Services\DemoTools::class, 'RESET_TABLES'))->getValue();

        $this->appConnection()->statement('TRUNCATE TABLE '.implode(', ', $tables).' CASCADE');
        $this->assertSame(0, (int) $this->appConnection()->selectOne('select count(*) as n from orders')->n);
    }

    #[DataProvider('forbiddenStatements')]
    public function test_app_account_cannot_change_structure_or_audit_logs(string $sql): void
    {
        $this->expectException(QueryException::class);
        $this->appConnection()->statement($sql);
    }

    public static function forbiddenStatements(): array
    {
        return [
            '關閉稽核紀錄觸發器' => ['ALTER TABLE audit_logs DISABLE TRIGGER ALL'],
            '關閉轉盤局觸發器' => ['ALTER TABLE minigame_rounds DISABLE TRIGGER ALL'],
            '修改稽核紀錄' => ["UPDATE audit_logs SET note = 'x'"],
            '刪除稽核紀錄' => ['DELETE FROM audit_logs'],
            '清空稽核紀錄' => ['TRUNCATE audit_logs'],
            '建立資料表' => ['CREATE TABLE privilege_test (id int)'],
            '刪除資料表' => ['DROP TABLE orders'],
            '修改 migration 紀錄' => ['DELETE FROM migrations'],
            '執行系統指令' => ["COPY (SELECT 1) TO PROGRAM 'id'"],
        ];
    }
}
