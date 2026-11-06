<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Package;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** 方案不能刪除；修改不影響已成立的訂單（訂單已快照價格與點數）。 */
class PackageController
{
    public function index(): View
    {
        return view('admin.packages', ['packages' => Package::orderBy('price')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $package = Package::create($this->validated($request) + ['active' => true]);
        AuditLog::record('package.created', $package, ['amount' => $package->price, 'note' => $package->name], Auth::guard('admin')->user());

        return back()->with('status', '方案已新增');
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $data = $this->validated($request) + ['active' => $request->boolean('active')];
        $package->update($data);
        AuditLog::record('package.updated', $package, ['amount' => $package->price, 'note' => $package->name.($package->active ? '（上架）' : '（下架）')], Auth::guard('admin')->user());

        return back()->with('status', '方案已更新');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:50',
            'price' => 'required|integer|min:1|max:1000000',
        ]);
    }
}
