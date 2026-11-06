@extends('shop.layout')
@section('title', '訂單狀態')
@section('head')
    @if (in_array($order->status, ['pending', 'paid'], true))
        <meta http-equiv="refresh" content="3">
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
    @if (in_array($order->status, ['pending', 'paid'], true))
        <p class="muted">以金流通知為準，頁面會自動更新。</p>
    @endif
    @if ($order->status === 'pending')
        <div class="actions"><a class="button secondary" href="{{ route('mock.pay.show', $order->order_no) }}">回付款頁</a></div>
    @endif
</div>
@endsection
