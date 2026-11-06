@extends('admin.layout')
@section('title', '客服工單 '.$ticket->ticket_no)
@section('content')
<h1>{{ $ticket->subject }}</h1>
<div class="card">
    <dl class="facts">
        <dt>工單編號</dt><dd><span class="code">{{ $ticket->ticket_no }}</span></dd>
        <dt>類別</dt><dd>{{ $ticket->categoryLabel() }}</dd>
        <dt>狀態</dt><dd><strong>{{ $ticket->statusLabel() }}</strong>@if ($ticket->closed_at)（{{ $ticket->closed_at->format('Y-m-d H:i') }} 結案）@endif</dd>
        <dt>玩家</dt><dd><a href="{{ route('admin.players.show', $ticket->player_id) }}">玩家 #{{ $ticket->player->game_player_id }}</a>@if ($ticket->player->isSuspended()) <span class="badge err">已停權</span>@endif</dd>
        @if ($ticket->order_no)
            <dt>相關訂單</dt>
            <dd>
                <span class="code">{{ $ticket->order_no }}</span>
                @if ($order)
                    ｜{{ $order->package_name }}｜{{ number_format($order->amount) }} 元｜實際狀態：{{ $order->statusLabel() }}（玩家看到：{{ $order->playerStatusLabel() }}）
                @endif
            </dd>
        @endif
        <dt>回覆通知</dt><dd>{{ $ticket->contact_email ?? '玩家沒有留 Email，只會在網站上看到回覆' }}</dd>
    </dl>
</div>

<div class="card">
    <h2>往來紀錄</h2>
    <ol class="thread">
        @foreach ($ticket->messages as $message)
            <li class="thread-item {{ $message->author_type === 'admin' ? 'from-staff' : 'from-player' }}">
                <div class="thread-meta"><strong>{{ $message->author_type === 'admin' ? '客服：'.($message->admin?->username ?? '管理員') : '玩家' }}</strong> · {{ $message->created_at->format('Y-m-d H:i') }}</div>
                <div class="thread-body">{{ $message->body }}</div>
            </li>
        @endforeach
    </ol>

    <form method="post" action="{{ route('admin.support.reply', $ticket) }}">
        @csrf
        <label for="body">回覆玩家</label>
        <textarea id="body" name="body" rows="5" maxlength="2000" required>{{ old('body') }}</textarea>
        @error('body')<p class="field-error">{{ $message }}</p>@enderror
        <p class="muted">玩家會在客服頁看到回覆@if ($ticket->contact_email)，也會收到通知信（寄到 Mailpit）@endif。請勿在回覆中提供完整卡號或帳號。</p>
        <label class="check"><input type="checkbox" name="close" value="1"> 回覆後結案</label>
        <div class="actions"><button type="submit">送出回覆</button></div>
    </form>
    @unless ($ticket->isClosed())
        <form method="post" action="{{ route('admin.support.close', $ticket) }}" data-confirm="確定不回覆直接結案？">
            @csrf
            <div class="actions"><button type="submit" class="secondary">直接結案</button></div>
        </form>
    @endunless
</div>
<p><a href="{{ route('admin.support') }}">← 回到工單列表</a></p>
@endsection
