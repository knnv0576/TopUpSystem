@extends('layouts.app')

@section('brand', '玩家中心')

@section('nav')
    @auth('game')
        <a href="{{ route('game.dashboard') }}">總覽</a>
        <a href="{{ route('game.wheel') }}">小遊戲</a>
        <a href="{{ route('game.red-packets') }}">紅包</a>
        <a href="{{ route('game.refunds') }}">退款</a>
        <a href="{{ route('game.ledger') }}">點數明細</a>
        <a href="{{ route('game.orders') }}">充值訂單</a>
    @endauth
@endsection

@section('account')
    @auth('game')
        <span>{{ auth('game')->user()->nickname }}（{{ auth('game')->user()->player_no }}）</span>
        <a href="{{ route('game.password') }}">改密碼</a>
        <form class="inline" method="post" action="{{ route('game.logout') }}">@csrf<button class="link" type="submit">登出</button></form>
    @endauth
@endsection
