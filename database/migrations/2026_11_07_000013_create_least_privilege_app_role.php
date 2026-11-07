<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 最小權限：應用程式改用只有讀寫權限的帳號，不是資料表擁有者，也不是超級使用者。
 * 這樣稽核紀錄與轉盤局的觸發器擋得住應用程式本身，SQL 注入也無法改結構或執行系統指令。
 *
 * 由擁有者帳號執行（php artisan migrate --database=pgsql_owner）。帳號名稱與密碼取自
 * pgsql 連線（DB_USERNAME、DB_PASSWORD）；已存在時只更新密碼與權限。
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = (string) config('database.connections.pgsql.username');
        $password = (string) config('database.connections.pgsql.password');
        $owner = DB::selectOne('select current_user as name')->name;

        if ($role === '' || $role === $owner) {
            throw new RuntimeException('DB_USERNAME 必須是另一個帳號，不能和資料表擁有者（DB_OWNER_USERNAME）相同。'
                .'舊版的 .env 請照 README「從舊版升級」修改：原本的 DB_USERNAME、DB_PASSWORD 改名為 DB_OWNER_*，再新增 DB_USERNAME=topup_app 與新的 DB_PASSWORD。');
        }
        if (strlen($password) < 16) {
            throw new RuntimeException('DB_PASSWORD 至少要 16 個字元');
        }

        $name = $this->identifier($role);
        $secret = DB::getPdo()->quote($password);
        $exists = DB::selectOne('select 1 as found from pg_roles where rolname = ?', [$role]);
        DB::statement(($exists ? "ALTER ROLE $name" : "CREATE ROLE $name").
            " LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD $secret");

        $database = $this->identifier(DB::getDatabaseName());
        $ownerName = $this->identifier($owner);
        DB::statement("GRANT CONNECT ON DATABASE $database TO $name");
        DB::statement("GRANT USAGE ON SCHEMA public TO $name");
        DB::statement("REVOKE CREATE ON SCHEMA public FROM PUBLIC");

        // 現有與之後由擁有者建立的資料表、序號都自動授權。
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE, TRUNCATE ON ALL TABLES IN SCHEMA public TO $name");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO $name");
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE $ownerName IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE, TRUNCATE ON TABLES TO $name");
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE $ownerName IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO $name");

        // 稽核紀錄只能新增與讀取；觸發器之外再擋一層。
        DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM $name");
        // migration 紀錄只給擁有者改。
        DB::statement("REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON migrations FROM $name");
    }

    public function down(): void
    {
        $name = $this->identifier((string) config('database.connections.pgsql.username'));
        $ownerName = $this->identifier(DB::selectOne('select current_user as name')->name);

        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE $ownerName IN SCHEMA public REVOKE ALL ON TABLES FROM $name");
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE $ownerName IN SCHEMA public REVOKE ALL ON SEQUENCES FROM $name");
        DB::statement("REVOKE ALL ON ALL TABLES IN SCHEMA public FROM $name");
        DB::statement("REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM $name");
        // 帳號是叢集層級，可能還有其他資料庫在用（例如測試資料庫），不刪除。
    }

    private function identifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
};
