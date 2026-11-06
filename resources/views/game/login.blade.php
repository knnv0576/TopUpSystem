@extends('game.layout')
@section('title', '玩家登入')
@section('content')
<h1>玩家登入</h1>
<div class="card">
    <form method="post" action="{{ route('game.login') }}">
        @csrf
        <label for="username">帳號</label>
        <input id="username" type="text" name="username" value="{{ old('username') }}" autocomplete="username" required>
        <label for="password">密碼</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
        <div class="actions"><button type="submit">登入</button></div>
    </form>
    <p class="muted">還沒有帳號？<a href="{{ route('game.register') }}">註冊</a></p>
</div>
@endsection
