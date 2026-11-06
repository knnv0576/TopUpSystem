@extends('game.layout')
@section('title', '前往充值網站')
@section('content')
<div class="card">
    <p>正在前往充值網站…</p>
    <form method="post" action="{{ route('shop.sso') }}" data-autosubmit>
        <input type="hidden" name="token" value="{{ $token }}">
        <button type="submit">如果沒有自動跳轉，請按這裡</button>
    </form>
</div>
@endsection
