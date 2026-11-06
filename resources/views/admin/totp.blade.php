@extends('admin.layout')
@section('title', '兩步驟驗證')
@section('content')
<h1>兩步驟驗證</h1>
<div class="card">
    @if ($setup)
        <p>第一次登入：請把下面的密鑰加入驗證器 App（類型選「依時間」），再輸入產生的 6 碼驗證碼。</p>
        <p>密鑰：<span class="code">{{ $secret }}</span></p>
        <p class="muted">otpauth 連結：<span class="code">{{ $uri }}</span></p>
    @endif
    <form method="post" action="{{ route('admin.totp') }}">
        @csrf
        <label for="code">6 碼驗證碼</label>
        <input id="code" type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
        <div class="actions"><button type="submit">驗證</button></div>
    </form>
</div>
@endsection
