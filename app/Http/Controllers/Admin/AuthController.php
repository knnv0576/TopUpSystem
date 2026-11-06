<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** 兩階段登入：密碼通過後只記下待驗證的管理員，TOTP 通過才真正登入 admin guard。 */
class AuthController
{
    private const LOGIN_ERROR = '帳號或密碼錯誤，或帳號暫時無法使用';

    public function showLogin(): View
    {
        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => 'required|string|max:32', 'password' => 'required|string|max:200']);
        $admin = AdminUser::where('username', $data['username'])->first();

        if (! $admin) {
            // 花費同樣的雜湊時間，避免從回應時間判斷帳號是否存在。
            // 輸入的帳號不寫入紀錄：有人可能把密碼打在帳號欄。
            Hash::make($data['password']);
            AuditLog::record('admin.login_failed', null, ['actor_type' => 'anonymous', 'note' => '帳號不存在']);

            return $this->loginFailed();
        }

        if (! $admin->canAuthenticate()) {
            AuditLog::record('admin.login_failed', $admin, ['actor_type' => 'anonymous', 'actor' => $admin->username, 'note' => $admin->isDisabled() ? '帳號已停用' : '帳號鎖定中']);

            return $this->loginFailed();
        }

        if (! Hash::check($data['password'], $admin->password)) {
            AuditLog::record('admin.login_failed', $admin, ['actor_type' => 'anonymous', 'actor' => $admin->username, 'note' => '密碼錯誤']);
            if ($admin->registerFailure()) {
                AuditLog::record('admin.locked', $admin, ['actor_type' => 'anonymous', 'actor' => $admin->username, 'note' => '連續登入失敗，帳號暫時鎖定']);
            }

            return $this->loginFailed();
        }

        // 失敗次數要等 TOTP 也通過才歸零，否則知道密碼的人可以無限猜驗證碼。
        $request->session()->regenerate();
        $request->session()->put('admin_pending_id', $admin->id);

        return redirect()->route('admin.totp');
    }

    public function showTotp(Request $request, TotpService $totp): View|RedirectResponse
    {
        $admin = $this->pendingAdmin($request);
        if (! $admin) {
            return redirect()->route('admin.login');
        }

        // 第一次登入：產生密鑰，驗證成功才算綁定完成。
        if (! $admin->totp_confirmed_at && ! $admin->totp_secret) {
            $admin->update(['totp_secret' => $totp->generateSecret()]);
        }

        return view('admin.totp', [
            'setup' => ! $admin->totp_confirmed_at,
            'secret' => $admin->totp_confirmed_at ? null : $admin->totp_secret,
            'uri' => $admin->totp_confirmed_at ? null : $totp->provisioningUri($admin, $admin->totp_secret),
        ]);
    }

    public function verifyTotp(Request $request, TotpService $totp): RedirectResponse
    {
        $admin = $this->pendingAdmin($request);
        if (! $admin) {
            return redirect()->route('admin.login');
        }

        $data = $request->validate(['code' => 'required|string']);
        if (! $totp->verify($admin, $data['code'])) {
            return back()->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        if (! $admin->totp_confirmed_at) {
            $admin->update(['totp_confirmed_at' => now()]);
            AuditLog::record('admin.totp_confirmed', $admin, [], $admin);
        }

        $admin->update(['last_login_at' => now()]);
        $request->session()->forget('admin_pending_id');
        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();
        $request->session()->put($admin->fingerprintSessionKey(), $admin->sessionFingerprint());
        AuditLog::record('admin.login', $admin, [], $admin);

        return redirect()->route('admin.home');
    }

    public function showPassword(): View
    {
        return view('admin.password', ['forced' => Auth::guard('admin')->user()->must_change_password]);
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => 'required|string|max:200',
            'password' => ['required', 'confirmed', 'max:200', 'different:current_password', Password::min(12)->letters()->numbers()],
        ], ['password.different' => '新密碼不能與目前的密碼相同']);

        $admin = Auth::guard('admin')->user();
        if (! Hash::check($data['current_password'], $admin->password)) {
            AuditLog::record('admin.password_change_failed', $admin, [], $admin);
            // 計入失敗次數：登入中的 session 被盜用時，不能拿這裡猜密碼。
            if ($admin->registerFailure()) {
                AuditLog::record('admin.locked', $admin, ['note' => '連續輸入錯誤的目前密碼，帳號暫時鎖定'], $admin);
            }

            return back()->withErrors(['current_password' => '目前的密碼不正確']);
        }

        $admin->update(['password' => $data['password'], 'must_change_password' => false, 'password_changed_at' => now()]);
        // 其他裝置的指紋因此失效；只有這個 session 更新成新的指紋，繼續保持登入。
        $request->session()->regenerate();
        $request->session()->put($admin->fingerprintSessionKey(), $admin->fresh()->sessionFingerprint());
        AuditLog::record('admin.password_changed', $admin, [], $admin);

        return redirect()->route('admin.home')->with('status', '密碼已更新');
    }

    public function logout(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();
        AuditLog::record('admin.logout', $admin, [], $admin);

        Auth::guard('admin')->logout();
        $request->session()->forget($admin->fingerprintSessionKey());
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function pendingAdmin(Request $request): ?AdminUser
    {
        $id = $request->session()->get('admin_pending_id');
        $admin = $id ? AdminUser::find($id) : null;

        if ($admin && $admin->isDisabled()) {
            $request->session()->forget('admin_pending_id');

            return null;
        }

        return $admin;
    }

    private function loginFailed(): RedirectResponse
    {
        return back()->withErrors(['username' => self::LOGIN_ERROR])->onlyInput('username');
    }
}
