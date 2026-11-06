@extends('layouts.app')

@section('brand', '管理後台')

@section('nav')
    @auth('admin')
        <a href="{{ route('admin.refunds') }}">退款審核</a>
        <a href="{{ route('admin.adjustments') }}">人工調整</a>
        <a href="{{ route('admin.players') }}">玩家</a>
        <a href="{{ route('admin.packages') }}">方案</a>
        <a href="{{ route('admin.minigame') }}">小遊戲</a>
        <a href="{{ route('admin.anomalies') }}">異常清單</a>
        <a href="{{ route('admin.audit') }}">稽核紀錄</a>
        @if (auth('admin')->user()->isSupervisor())
            <a href="{{ route('admin.accounts') }}">管理員帳號</a>
        @endif
        @if (auth('admin')->user()->isSupervisor() && Route::has('admin.demo'))
            <a href="{{ route('admin.demo') }}">測試面板</a>
        @endif
    @endauth
@endsection

@section('account')
    @auth('admin')
        <span>{{ auth('admin')->user()->username }}（{{ auth('admin')->user()->roleLabel() }}）</span>
        <a href="{{ route('admin.password') }}">改密碼</a>
        <form class="inline" method="post" action="{{ route('admin.logout') }}">@csrf<button class="link" type="submit">登出</button></form>
    @endauth
@endsection
