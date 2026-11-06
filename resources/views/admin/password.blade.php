@extends('admin.layout')
@section('title', '更改密碼')
@section('content')
<h1>更改密碼</h1>
<div class="card">
    @if ($forced)
        <p class="warning">目前使用的是初始密碼，更改後才能使用後台。</p>
    @endif
    <form method="post" action="{{ route('admin.password') }}">
        @csrf
        <label for="current_password">目前的密碼</label>
        <input id="current_password" type="password" name="current_password" autocomplete="current-password" required>
        <label for="password">新密碼（至少 12 字，需包含英文與數字）</label>
        <input id="password" type="password" name="password" autocomplete="new-password" required>
        <label for="password_confirmation">再次輸入新密碼</label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
        <div class="actions"><button type="submit">更新密碼</button></div>
    </form>
</div>
@endsection
