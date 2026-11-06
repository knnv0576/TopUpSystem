@extends('layouts.app')

@section('brand', '充值網站')
@section('body_class', 'theme-game theme-shop')
@section('styles')<link rel="stylesheet" href="/game-theme.css">@endsection

@section('nav')
    <a href="{{ route('shop.index') }}">方案</a>
    <a href="{{ route('shop.support.index') }}">客服</a>
    <a href="{{ route('shop.back-to-game') }}">回玩家中心</a>
@endsection

@section('footer')
    @include('shop.partials.footer')
@endsection
