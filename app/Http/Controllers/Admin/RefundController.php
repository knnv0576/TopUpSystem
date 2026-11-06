<?php

namespace App\Http\Controllers\Admin;

use App\Game\GameGateway;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\RefundRequest;
use App\Services\RefundService;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class RefundController
{
    public function __construct(private RefundService $refunds, private TotpService $totp) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');
        $query = RefundRequest::with('player')->orderByDesc('id');
        if (is_string($status) && array_key_exists($status, RefundRequest::ADMIN_LABELS)) {
            $query->where('status', $status);
        }

        return view('admin.refunds', ['refunds' => $query->paginate(30)->withQueryString(), 'status' => $status]);
    }

    public function show(RefundRequest $refund, GameGateway $game): View
    {
        try {
            $playerInfo = $game->getPlayer($refund->player->game_player_id);
        } catch (Throwable $e) {
            report($e);
            $playerInfo = null;
        }

        $logs = AuditLog::where('subject_type', 'RefundRequest')->where('subject_id', $refund->id)->orderBy('id')->get();
        $anomalies = Anomaly::where('subject_type', 'RefundRequest')->where('subject_id', $refund->id)->whereNull('resolved_at')->get();

        // 最後才讀申請狀態：Webhook 可能在上面的呼叫期間抵達，狀態不能比下方的紀錄舊，否則處理按鈕不會顯示。
        $refund->refresh()->load(['player', 'reviewer', 'supervisor', 'payout']);

        return view('admin.refund', [
            'refund' => $refund,
            'playerInfo' => $playerInfo,
            'logs' => $logs,
            'anomalies' => $anomalies,
        ]);
    }

    public function reviewApprove(RefundRequest $refund): RedirectResponse
    {
        return $this->attempt($refund, fn () => $this->refunds->reviewApprove($refund, $this->admin()), '已同意，交由覆核');
    }

    public function reviewReject(Request $request, RefundRequest $refund): RedirectResponse
    {
        $reason = $this->reason($request);

        return $this->attempt($refund, fn () => $this->refunds->reviewReject($refund, $this->admin(), $reason), '已拒絕，點數解凍');
    }

    public function approve(Request $request, RefundRequest $refund): RedirectResponse
    {
        if (! $this->totp->verify($this->admin(), (string) $request->input('code'))) {
            return back()->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        return $this->attempt($refund, fn () => $this->refunds->supervisorApprove($refund, $this->admin()), '已核准，開始扣點與撥款');
    }

    public function reject(Request $request, RefundRequest $refund): RedirectResponse
    {
        $reason = $this->reason($request);

        return $this->attempt($refund, fn () => $this->refunds->supervisorReject($refund, $this->admin(), $reason), '已拒絕，點數解凍');
    }

    public function retry(RefundRequest $refund): RedirectResponse
    {
        return $this->attempt($refund, fn () => $this->refunds->retryPayout($refund, $this->admin()), '已重新送出撥款');
    }

    public function close(Request $request, RefundRequest $refund): RedirectResponse
    {
        $reason = $this->reason($request);
        if (! $this->totp->verify($this->admin(), (string) $request->input('code'))) {
            return back()->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        return $this->attempt($refund, fn () => $this->refunds->closeWithReturn($refund, $this->admin(), $reason), '已結案，點數還給玩家');
    }

    private function attempt(RefundRequest $refund, callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return redirect()->route('admin.refunds.show', $refund)->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()->route('admin.refunds.show', $refund)->with('status', $message);
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => 'required|string|max:200'], ['reason.required' => '請填寫理由'])['reason'];
    }

    private function admin()
    {
        return Auth::guard('admin')->user();
    }
}
