@extends('game.layout')
@section('title', '退款')
@section('content')
<h1>退款</h1>
<div class="card">
    <h2>申請退款</h2>
    <p class="muted">可用點數 {{ number_format($wallet->available()) }} 點。100 點 = 1 元；點數須為 100 的倍數，單次至少 10,000 點（100 元）。</p>
    <p class="warning">這是模擬撥款，不會有真實匯款。請勿填寫真實銀行帳號。</p>
    <form method="post" action="{{ route('game.refunds.submit') }}" data-dialog="#refund-dialog">
        @csrf
        <input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}">
        <input type="hidden" name="acknowledged" value="">
        <label for="points">退款點數</label>
        <input id="points" type="number" name="points" min="10000" step="100" value="{{ old('points') }}" required>
        <label for="bank_code">模擬銀行代碼（3 碼）</label>
        <input id="bank_code" type="text" name="bank_code" value="{{ old('bank_code') }}" inputmode="numeric" pattern="\d{3}" maxlength="3" autocomplete="off" required>
        <label for="bank_account">模擬帳號（6–16 碼）</label>
        <input id="bank_account" type="text" name="bank_account" inputmode="numeric" pattern="\d{6,16}" maxlength="16" autocomplete="off" required>
        <div class="actions"><button type="submit">送出申請</button></div>
    </form>
</div>
<dialog id="refund-dialog">
    <h2>確認送出退款申請</h2>
    <p>送出後無法撤銷，申請的點數會立即凍結到審核完成。請勿填寫真實銀行帳號。</p>
    <div class="actions">
        <button type="button" data-dialog-ok>確認送出</button>
        <button type="button" class="secondary" data-dialog-cancel>返回修改</button>
    </div>
</dialog>
<div class="card">
    <h2>退款紀錄</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>編號</th><th class="num">點數</th><th class="num">金額</th><th>撥款帳戶</th><th>進度</th><th>理由</th><th>申請時間</th></tr></thead>
        <tbody>
        @forelse ($refunds as $refund)
            <tr>
                <td>#{{ $refund['id'] }}</td>
                <td class="num">{{ number_format($refund['points']) }}</td>
                <td class="num">{{ number_format($refund['amount']) }} 元</td>
                <td>{{ $refund['account'] }}</td>
                <td>{{ $refund['status'] }}</td>
                <td>{{ $refund['reason'] }}</td>
                <td>{{ \Illuminate\Support\Carbon::parse($refund['created_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">還沒有申請紀錄</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
