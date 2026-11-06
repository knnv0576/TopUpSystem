<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** 玩家的客服頁（充值網站）。玩家中心的「客服」會經由單次 token 登入後進到這裡。 */
class SupportController
{
    public function __construct(private SupportService $support) {}

    public function index(Request $request): View
    {
        $player = Auth::guard('shop')->user();
        $order = (string) $request->query('order', '');

        return view('shop.support.index', [
            'tickets' => SupportTicket::where('player_id', $player->id)->orderByDesc('last_message_at')->limit(50)->get(),
            'categories' => SupportTicket::CATEGORIES,
            'prefillOrder' => preg_match('/^T[0-9A-Z]{10,30}$/', $order) ? $order : '',
            'prefillCategory' => array_key_exists($request->query('category'), SupportTicket::CATEGORIES) ? $request->query('category') : '',
            'orders' => Order::where('player_id', $player->id)->orderByDesc('id')->limit(20)->get(['order_no', 'package_name', 'status', 'failure_code', 'source', 'created_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(SupportTicket::CATEGORIES))],
            'subject' => 'required|string|min:4|max:100',
            'order_no' => 'nullable|string|max:32',
            'contact_email' => 'nullable|email:rfc|max:120',
            'body' => 'required|string|min:10|max:2000',
        ], [
            'category.required' => '請選擇問題類別',
            'category.in' => '請選擇問題類別',
            'subject.required' => '請輸入主旨',
            'subject.min' => '主旨至少 4 個字',
            'subject.max' => '主旨最多 100 個字',
            'contact_email.email' => 'Email 格式不正確',
            'contact_email.max' => 'Email 最多 120 個字',
            'body.required' => '請描述你遇到的問題',
            'body.min' => '問題描述至少 10 個字',
            'body.max' => '問題描述最多 2,000 個字',
        ]);
        $data['order_no'] = ($data['order_no'] ?? '') !== '' ? strtoupper(trim($data['order_no'])) : null;
        $data['contact_email'] = ($data['contact_email'] ?? '') !== '' ? $data['contact_email'] : null;

        $ticket = $this->support->create(Auth::guard('shop')->user(), $data);

        return redirect()->route('shop.support.show', $ticket)->with('status', "已收到你的問題，工單編號 {$ticket->ticket_no}，客服回覆後會顯示在這裡");
    }

    public function show(SupportTicket $ticket): View
    {
        $this->authorizeOwner($ticket);

        return view('shop.support.show', ['ticket' => $ticket->load('messages')]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->authorizeOwner($ticket);
        $data = $request->validate(['body' => 'required|string|min:2|max:2000'], [
            'body.required' => '請輸入回覆內容',
            'body.min' => '請輸入回覆內容',
            'body.max' => '回覆最多 2,000 個字',
        ]);

        $this->support->playerReply($ticket, $data['body']);

        return redirect()->route('shop.support.show', $ticket)->with('status', '已送出回覆');
    }

    private function authorizeOwner(SupportTicket $ticket): void
    {
        abort_unless($ticket->player_id === Auth::guard('shop')->id(), 404);
    }
}
