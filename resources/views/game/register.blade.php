@extends('game.layout')
@section('title', '玩家註冊')
@section('content')
<h1>玩家註冊</h1>
<div class="card">
    <form method="post" action="{{ route('game.register') }}">
        @csrf
        <label for="username">帳號（3–32 字，英數字、- 或 _）</label>
        <input id="username" type="text" name="username" value="{{ old('username') }}" autocomplete="username" required>
        <label for="nickname">暱稱（可重複，之後可修改）</label>
        <input id="nickname" type="text" name="nickname" value="{{ old('nickname') }}" required>
        <label for="password">密碼（至少 10 字，需包含英文與數字）</label>
        <input id="password" type="password" name="password" autocomplete="new-password" required>
        <label for="password_confirmation">再次輸入密碼</label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
        <div class="actions"><button type="submit">註冊</button></div>
    </form>
    <p class="muted">已有帳號？<a href="{{ route('game.login') }}">登入</a></p>
</div>
@endsection
