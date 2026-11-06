@extends('admin.layout')
@section('title', '測試面板')
@section('content')
<h1>DEMO 測試面板</h1>
<div class="grid">
    <div class="card">
        <h2>下一次撥款的結果</h2>
        <p class="muted">目前設定：{{ $payoutResults[$nextPayout] ?? $nextPayout }}。用過一次後回到撥款成功。</p>
        <form method="post" action="{{ route('admin.demo.next-payout') }}">
            @csrf
            <label for="result">結果</label>
            <select id="result" name="result">
                @foreach ($payoutResults as $value => $label)
                    <option value="{{ $value }}" @selected($nextPayout === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="actions"><button type="submit">設定</button></div>
        </form>
    </div>
    <div class="card">
        <h2>下一次遊戲內充值的結果</h2>
        <p class="muted">目前設定：{{ $gameTopupResults[$nextGameTopup] ?? $nextGameTopup }}。用過一次後回到付款成功。</p>
        <form method="post" action="{{ route('admin.demo.next-game-topup') }}">
            @csrf
            <label for="game_topup_result">結果</label>
            <select id="game_topup_result" name="result">
                @foreach ($gameTopupResults as $value => $label)
                    <option value="{{ $value }}" @selected($nextGameTopup === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="actions"><button type="submit">設定</button></div>
        </form>
    </div>
    <div class="card">
        <h2>讓紅包到期</h2>
        <form method="post" action="{{ route('admin.demo.expire-packet') }}">
            @csrf
            <label for="packet_id">紅包編號</label>
            <input id="packet_id" type="number" name="packet_id" min="1" required>
            <div class="actions"><button type="submit">立即到期並退回</button></div>
        </form>
    </div>
    <div class="card">
        <h2>讓申請逾時</h2>
        <form method="post" action="{{ route('admin.demo.overdue-refund') }}">
            @csrf
            <label for="refund_id">退款申請編號</label>
            <input id="refund_id" type="number" name="refund_id" min="1" required>
            <div class="actions"><button type="submit">設為審核逾時</button></div>
        </form>
    </div>
    <div class="card">
        <h2>重置測試資料</h2>
        <p class="warning">清除所有玩家、訂單、紅包與退款。管理員帳號、方案與稽核紀錄會保留。</p>
        <form method="post" action="{{ route('admin.demo.reset') }}" data-confirm="確定清除全部測試資料？">
            @csrf
            <label for="confirm">輸入 RESET 以確認</label>
            <input id="confirm" type="text" name="confirm" required>
            <div class="actions"><button type="submit">重置</button></div>
        </form>
    </div>
</div>
@endsection
