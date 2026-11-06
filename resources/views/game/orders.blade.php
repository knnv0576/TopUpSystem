@extends('game.layout')
@section('title', '充值訂單')
@section('content')
<h1>充值訂單</h1>
<div class="card">
    <div class="table-wrap">
    <table>
        <thead><tr><th>訂單編號</th><th>方案</th><th class="num">金額</th><th class="num">點數</th><th>狀態</th><th>建立時間</th></tr></thead>
        <tbody>
        @forelse ($orders as $order)
            <tr>
                <td><span class="code">{{ $order['order_no'] }}</span></td>
                <td>{{ $order['package_name'] }}</td>
                <td class="num">{{ number_format($order['amount']) }} 元</td>
                <td class="num">{{ number_format($order['points']) }}</td>
                <td>{{ $order['status'] }}</td>
                <td>{{ \Illuminate\Support\Carbon::parse($order['created_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">還沒有訂單</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
