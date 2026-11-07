@extends('shop.layout')
@section('title', '訂單狀態')
@section('head')
    @if ($refresh)
        <meta http-equiv="refresh" content="1">
    @endif
@endsection
@section('content')
<h1>訂單狀態</h1>
<div class="card">
    <dl class="facts">
        <dt>訂單編號</dt><dd><span class="code">{{ $order->order_no }}</span></dd>
        <dt>方案</dt><dd>{{ $order->package_name }}</dd>
        <dt>金額</dt><dd>{{ number_format($order->amount) }} 元</dd>
        <dt>點數</dt><dd>{{ number_format($order->points) }} 點</dd>
        <dt>狀態</dt><dd><strong>{{ $order->playerStatusLabel() }}</strong></dd>
    </dl>
    @if ($refresh)
        <p class="muted">以金流通知為準，頁面會自動更新；入帳完成後會回到玩家中心。</p>
    @elseif ($noticeOverdue)
        <p class="muted">還沒收到金流通知，頁面已停止自動更新。請稍後重新整理；一直沒有結果請聯繫客服。</p>
    @elseif ($payable)
        <p class="muted">這筆訂單還沒付款，請回付款頁完成付款。</p>
    @endif
    @if ($order->status === 'failed' && $order->failure_code === 'card_declined')
        <p class="muted">這筆交易沒有扣款。請洽詢發卡銀行，或回方案頁重新下單，改用其他卡片或付款方式。</p>
    @endif
    <div class="actions">
        @if ($order->failedForPlayer())
            <a class="button" href="{{ route('shop.index') }}">重新選擇方案</a>
            <a class="button secondary" href="{{ route('shop.support.index', ['order' => $order->order_no, 'category' => 'topup']) }}">聯繫客服</a>
        @endif
        @if ($payable)
            <a class="button secondary" href="{{ route('mock.pay.show', $order->order_no) }}">回付款頁</a>
        @endif
        <a class="button secondary" href="{{ route('shop.back-to-game') }}">回玩家中心</a>
    </div>
</div>
@endsection
