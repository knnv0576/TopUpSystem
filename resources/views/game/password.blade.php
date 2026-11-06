@extends('game.layout')
@section('title', '更改密碼')
@section('content')
<h1>更改密碼</h1>
<div class="card">
    <p class="muted">更改後，其他裝置的登入都會失效，這台裝置會保持登入；充值網站要重新從玩家中心進入。</p>
    <form method="post" action="{{ route('game.password.update') }}">
        @csrf
        <label for="current_password">目前的密碼</label>
        <input id="current_password" type="password" name="current_password" autocomplete="current-password" required>
        <label for="password">新密碼（至少 10 字，需包含英文與數字）</label>
        <input id="password" type="password" name="password" autocomplete="new-password" required>
        <label for="password_confirmation">再次輸入新密碼</label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
        <div class="actions"><button type="submit">更新密碼</button></div>
    </form>
</div>
@endsection
