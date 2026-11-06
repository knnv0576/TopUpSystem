@extends('game.layout')
@section('title', '確認紅包')
@section('content')
<h1>確認紅包</h1>
<div class="card">
    <dl class="facts">
        <dt>收件者編號</dt><dd><span class="code">{{ $recipient->player_no }}</span></dd>
        <dt>收件者暱稱</dt><dd>{{ $recipient->nickname }}</dd>
        <dt>點數</dt><dd>{{ number_format($data['points']) }} 點</dd>
        <dt>祝福語</dt><dd>{{ $data['message'] ?? '（未填）' }}</dd>
    </dl>
    <p class="warning">送出後會立即扣點。對方 24 小時內未領取才會退回。</p>
    <form method="post" action="{{ route('game.red-packets.send') }}">
        @csrf
        <input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}">
        <input type="hidden" name="player_no" value="{{ $recipient->player_no }}">
        <input type="hidden" name="points" value="{{ $data['points'] }}">
        <input type="hidden" name="message" value="{{ $data['message'] ?? '' }}">
        <div class="actions">
            <button type="submit">確認送出</button>
            <a class="button secondary" href="{{ route('game.red-packets') }}">取消</a>
        </div>
    </form>
</div>
@endsection
