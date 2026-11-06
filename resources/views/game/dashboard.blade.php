@extends('game.layout')
@section('title', '玩家中心')
@section('content')
<h1>總覽</h1>
<div class="grid">
    <div class="card stat"><div class="label">點數餘額</div><div class="value">{{ number_format($wallet->balance) }}</div></div>
    <div class="card stat"><div class="label">凍結中</div><div class="value">{{ number_format($wallet->frozen) }}</div></div>
    <div class="card stat"><div class="label">可用點數</div><div class="value">{{ number_format($wallet->available()) }}</div></div>
</div>
<div class="grid">
    <div class="card">
        <h2>充值</h2>
        <p class="muted">1 元 = 100 點。付款為模擬流程。</p>
        <form method="post" action="{{ route('game.go-shop') }}">@csrf<button type="submit">前往充值網站</button></form>
    </div>
    <div class="card">
        <h2>完成任務</h2>
        <p class="muted">測試用：自訂要取得的點數。</p>
        <form method="post" action="{{ route('game.task') }}">
            @csrf
            <input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}">
            <label for="points">點數</label>
            <input id="points" type="number" name="points" min="1" step="1" required>
            <div class="actions"><button type="submit">取得點數</button></div>
        </form>
    </div>
    <div class="card">
        <h2>個人資料</h2>
        <dl class="facts">
            <dt>玩家編號</dt><dd><span class="code">{{ $player->player_no }}</span></dd>
            <dt>帳號</dt><dd>{{ $player->username }}</dd>
        </dl>
        <form method="post" action="{{ route('game.nickname') }}">
            @csrf
            <label for="nickname">暱稱</label>
            <input id="nickname" type="text" name="nickname" value="{{ $player->nickname }}" maxlength="32" required>
            <div class="actions"><button class="secondary" type="submit">更新暱稱</button></div>
        </form>
    </div>
</div>
@endsection
