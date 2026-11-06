@extends('game.layout')
@section('title', $title ?? '前往充值網站')
@section('content')
<div class="card">
    <p>正在{{ $title ?? '前往充值網站' }}…</p>
    <form method="post" action="{{ route('shop.sso') }}" data-autosubmit>
        <input type="hidden" name="token" value="{{ $token }}">
        @if (! empty($next))<input type="hidden" name="next" value="{{ $next }}">@endif
        <button type="submit">如果沒有自動跳轉，請按這裡</button>
    </form>
</div>
@endsection
