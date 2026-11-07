<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Package;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 方案不能刪除；修改不影響已成立的訂單（訂單已快照價格與點數）。
 * 價格決定玩家付多少錢、拿多少點，新增與修改都要再驗證 TOTP，稽核紀錄保留修改前後的值。
 */
class PackageController
{
    public function __construct(private TotpService $totp) {}

    public function index(): View
    {
        return view('admin.packages', ['packages' => Package::orderBy('price')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $admin = Auth::guard('admin')->user();
        if (! $this->totp->verify($admin, (string) $request->input('code'))) {
            return back()->withInput($request->only('name', 'price'))->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        $package = Package::create($data + ['active' => true]);
        AuditLog::record('package.created', $package, ['to' => 'active', 'amount' => $package->price, 'note' => "名稱：{$package->name}；價格：".number_format($package->price).' 元'], $admin);

        return back()->with('status', '方案已新增');
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $data = $this->validated($request) + ['active' => $request->boolean('active')];
        $admin = Auth::guard('admin')->user();
        if (! $this->totp->verify($admin, (string) $request->input('code'))) {
            return back()->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        $before = $package->only(['name', 'price', 'active']);
        $package->update($data);

        AuditLog::record('package.updated', $package, [
            'from' => $before['active'] ? 'active' : 'inactive',
            'to' => $package->active ? 'active' : 'inactive',
            'amount' => $package->price,
            'note' => $this->changes($before, $package),
        ], $admin);

        return back()->with('status', '方案已更新');
    }

    /** 稽核紀錄的變更內容：每個欄位都寫出修改前與修改後。 */
    private function changes(array $before, Package $after): string
    {
        $state = fn (bool $active) => $active ? '上架' : '下架';

        return implode('；', [
            "名稱：{$before['name']} → {$after->name}",
            '價格：'.number_format($before['price']).' → '.number_format($after->price).' 元',
            '狀態：'.$state($before['active']).' → '.$state($after->active),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:50',
            'price' => 'required|integer|min:1|max:1000000',
        ]);
    }
}
