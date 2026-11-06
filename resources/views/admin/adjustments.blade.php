@extends('admin.layout')
@section('title', '人工調整')
@section('content')
<h1>人工調整</h1>
<div class="card">
    <p class="muted">發起人工調整請到「玩家」頁面查詢玩家。</p>
    @include('admin.partials.adjustment-table', ['adjustments' => $adjustments, 'showPlayer' => true])
    <div class="pagination">{{ $adjustments->links('pagination::simple-tailwind') }}</div>
</div>
@endsection
