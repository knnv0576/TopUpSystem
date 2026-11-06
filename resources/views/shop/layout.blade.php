@extends('layouts.app')

@section('brand', '充值網站')

@section('nav')
    <a href="{{ route('shop.index') }}">方案</a>
    <a href="{{ route('shop.back-to-game') }}">回玩家中心</a>
@endsection
