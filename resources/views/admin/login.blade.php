@extends('admin.layout')
@section('title', '後台登入')
@section('content')
<h1>後台登入</h1>
<div class="card">
    <form method="post" action="{{ route('admin.login') }}">
        @csrf
        <label for="username">帳號</label>
        <input id="username" type="text" name="username" value="{{ old('username') }}" autocomplete="username" required>
        <label for="password">密碼</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
        <div class="actions"><button type="submit">下一步</button></div>
    </form>
</div>
@endsection
