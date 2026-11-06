<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\ApplyPlayerSuspension;
use App\Jobs\UnfreezeRefundPoints;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\PointGrant;
use App\Models\RefundRequest;
use App\Services\FulfillmentService;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

class AnomalyController
{
    public function index(Request $request): View
    {
        $showResolved = $request->boolean('resolved');

        return view('admin.anomalies', [
            'anomalies' => Anomaly::when(! $showResolved, fn ($q) => $q->whereNull('resolved_at'))->orderByDesc('id')->paginate(30)->withQueryString(),
            'showResolved' => $showResolved,
        ]);
    }

    public function resolve(Request $request, Anomaly $anomaly): RedirectResponse
    {
        $note = $request->validate(['note' => 'required|string|max:200'], ['note.required' => '請填寫說明'])['note'];
        $admin = Auth::guard('admin')->user();

        $anomaly->update(['resolved_at' => now(), 'resolved_by' => $admin->id, 'resolution_note' => $note]);
        AuditLog::record('anomaly.resolved', $anomaly, ['note' => $note], $admin);

        return back()->with('status', '已標記為處理完成');
    }

    public function retry(Request $request, Anomaly $anomaly, FulfillmentService $fulfillment, TotpService $totp): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        // 補入點會直接增加玩家點數，所以要再驗證 TOTP。
        if (in_array($anomaly->kind, ['grant_failed', 'grant_held_suspended'], true)
            && ! $totp->verify($admin, (string) $request->input('code'))) {
            return back()->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        try {
            match ($anomaly->kind) {
                'grant_failed', 'grant_held_suspended' => $fulfillment->retry(PointGrant::findOrFail($anomaly->subject_id), $admin),
                'unfreeze_failed' => UnfreezeRefundPoints::dispatch(RefundRequest::findOrFail($anomaly->subject_id)->id),
                'suspension_sync_failed' => ApplyPlayerSuspension::dispatch($anomaly->subject_id),
                default => throw new RuntimeException('這類異常請到對應頁面處理'),
            };
        } catch (RuntimeException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        AuditLog::record('anomaly.retried', $anomaly, ['note' => $anomaly->kind], $admin);

        return back()->with('status', '已重新執行');
    }
}
