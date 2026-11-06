<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Package;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 每個管理員有各自的隨機初始密碼：不寫入任何檔案，只顯示這一次，第一次登入時必須更改。
        foreach ([AdminUser::REVIEWER, AdminUser::SUPERVISOR] as $role) {
            $password = Str::password(20, symbols: false);
            $admin = AdminUser::firstOrCreate(['username' => $role], [
                'password' => $password,
                'role' => $role,
                'must_change_password' => true,
            ]);

            if ($admin->wasRecentlyCreated) {
                $this->command?->warn("{$role} 的初始密碼：{$password}");
            }
        }

        foreach ([100, 300, 1000, 3000] as $price) {
            Package::firstOrCreate(['price' => $price], ['name' => number_format($price).' 元方案', 'active' => true]);
        }
    }
}
