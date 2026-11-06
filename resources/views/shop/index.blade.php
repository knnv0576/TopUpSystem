@extends('shop.layout')
@section('title', '充值方案')
@section('content')
<h1>選擇充值方案</h1>
<div class="packages">
    @forelse ($packages as $package)
        <div class="card package">
            <h2>{{ $package->name }}</h2>
            <div class="price">{{ number_format($package->price) }} 元</div>
            <p class="muted">{{ number_format($package->points()) }} 點</p>
            <form method="post" action="{{ route('shop.orders.create') }}">
                @csrf
                <input type="hidden" name="package_id" value="{{ $package->id }}">
                <button type="submit">購買</button>
            </form>
        </div>
    @empty
        <div class="card muted">目前沒有上架的方案</div>
    @endforelse
</div>
@endsection
