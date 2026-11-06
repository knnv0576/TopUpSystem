@extends('game.layout')
@section('title', '紅包')
@section('content')
@php
    $labels = ['sent' => '待領取', 'claimed' => '已領取', 'expired' => '逾時退回', 'returned' => '已退回'];
@endphp
<h1>紅包</h1>
<div class="card">
    <h2>發紅包</h2>
    <p class="muted">可用點數 {{ number_format($wallet->available()) }} 點。發送時先扣點，24 小時未領會退回。</p>
    <form method="post" action="{{ route('game.red-packets.confirm') }}">
        @csrf
        <label for="player_no">對方的玩家編號</label>
        <input id="player_no" type="text" name="player_no" value="{{ old('player_no') }}" maxlength="12" required>
        <label for="points">點數</label>
        <input id="points" type="number" name="points" min="1" step="1" value="{{ old('points') }}" required>
        <label for="message">祝福語（可不填）</label>
        <input id="message" type="text" name="message" value="{{ old('message') }}" maxlength="100">
        <div class="actions"><button type="submit">下一步</button></div>
    </form>
</div>
<div class="card">
    <h2>紅包匣</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>編號</th><th>來自</th><th class="num">點數</th><th>祝福語</th><th>期限</th><th>狀態</th><th></th></tr></thead>
        <tbody>
        @forelse ($inbox as $packet)
            <tr>
                <td>#{{ $packet->id }}</td>
                <td>{{ $packet->sender->nickname }}（{{ $packet->sender->player_no }}）</td>
                <td class="num">{{ number_format($packet->points) }}</td>
                <td>{{ $packet->message }}</td>
                <td>{{ $packet->expires_at->format('Y-m-d H:i') }}</td>
                <td>{{ $labels[$packet->status] ?? $packet->status }}</td>
                <td>
                    @if ($packet->status === 'sent' && $packet->expires_at->isFuture())
                        <form class="inline" method="post" action="{{ route('game.red-packets.claim', $packet->id) }}">@csrf<button type="submit">領取</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">還沒有收到紅包</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="card">
    <h2>已發送</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>編號</th><th>給</th><th class="num">點數</th><th>祝福語</th><th>期限</th><th>狀態</th></tr></thead>
        <tbody>
        @forelse ($sent as $packet)
            <tr>
                <td>#{{ $packet->id }}</td>
                <td>{{ $packet->recipient->nickname }}（{{ $packet->recipient->player_no }}）</td>
                <td class="num">{{ number_format($packet->points) }}</td>
                <td>{{ $packet->message }}</td>
                <td>{{ $packet->expires_at->format('Y-m-d H:i') }}</td>
                <td>{{ $labels[$packet->status] ?? $packet->status }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">還沒有發送紀錄</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
