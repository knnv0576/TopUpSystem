<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** 管理員帳號檢視與停用。忘記密碼或遺失 TOTP 請用 topup:admin-reset 指令。 */
class AccountController
{
    public function __construct(private TotpService $totp) {}

    public function index(): View
    {
        return view('admin.accounts', ['accounts' => AdminUser::orderBy('username')->get()]);
    }

    public function disable(Request $request, AdminUser $account): RedirectResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:200'], ['reason.required' => '請填寫理由'])['reason'];
        $admin = Auth::guard('admin')->user();

        if ($account->is($admin)) {
            return back()->withErrors(['action' => '不能停用自己的帳號']);
        }
        if (! $this->totp->verify($admin, (string) $request->input('code'))) {
            return back()->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        $account->update(['disabled_at' => now()]);
        AuditLog::record('admin.disabled', $account, ['to' => 'disabled', 'note' => $reason], $admin);

        return back()->with('status', "已停用 {$account->username}");
    }

    public function enable(Request $request, AdminUser $account): RedirectResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:200'], ['reason.required' => '請填寫理由'])['reason'];
        $admin = Auth::guard('admin')->user();

        if (! $this->totp->verify($admin, (string) $request->input('code'))) {
            return back()->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        $account->update(['disabled_at' => null]);
        AuditLog::record('admin.enabled', $account, ['from' => 'disabled', 'note' => $reason], $admin);

        return back()->with('status', "已恢復 {$account->username}");
    }
}
