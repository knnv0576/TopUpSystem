<?php

namespace App\Http\Controllers\Admin;

use App\Models\Order;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 後台客服工單：初審與覆核都可以回覆與結案。 */
class SupportController
{
    public function __construct(private SupportService $support) {}

    public function index(Request $request): View
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(array_merge(['all'], array_keys(SupportTicket::STATUS_LABELS)))]])['status'] ?? 'open';

        return view('admin.support.index', [
            'tickets' => SupportTicket::with('player')
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->orderByDesc('last_message_at')->paginate(30)->withQueryString(),
            'status' => $status,
            'counts' => SupportTicket::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(SupportTicket $ticket): View
    {
        return view('admin.support.show', [
            'ticket' => $ticket->load('messages.admin', 'player'),
            'order' => $ticket->order_no ? Order::where('order_no', $ticket->order_no)->first() : null,
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|min:2|max:2000', 'close' => 'nullable|boolean'], [
            'body.required' => '請輸入回覆內容',
            'body.min' => '請輸入回覆內容',
            'body.max' => '回覆最多 2,000 個字',
        ]);
        $close = $request->boolean('close');

        $this->support->adminReply($ticket, Auth::guard('admin')->user(), $data['body'], $close);

        return redirect()->route('admin.support.show', $ticket)->with('status', $close ? '已回覆並結案' : '已回覆');
    }

    public function close(SupportTicket $ticket): RedirectResponse
    {
        $this->support->close($ticket, Auth::guard('admin')->user());

        return redirect()->route('admin.support.show', $ticket)->with('status', '已結案');
    }
}
