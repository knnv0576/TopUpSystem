@extends('shop.layout')
@section('title', '模擬付款')
@section('content')
<h1>模擬付款頁</h1>
<div class="card">
    <p class="warning">這是模擬金流，不會產生真實交易。</p>
    <dl class="facts">
        <dt>訂單編號</dt><dd><span class="code">{{ $order->order_no }}</span></dd>
        <dt>方案</dt><dd>{{ $order->package_name }}</dd>
        <dt>應付金額</dt><dd>{{ number_format($order->amount) }} 元</dd>
        <dt>可得點數</dt><dd>{{ number_format($order->points) }} 點</dd>
    </dl>
    <form method="post" action="{{ route('mock.pay.submit', $order->order_no) }}">
        @csrf
        <label for="scenario">選擇情境</label>
        <select id="scenario" name="scenario">
            @foreach ($scenarios as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <div class="actions"><button type="submit">送出</button></div>
    </form>
</div>
@endsection
