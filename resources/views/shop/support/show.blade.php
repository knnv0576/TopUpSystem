@extends('shop.layout')
@section('title', '客服工單 '.$ticket->ticket_no)
@section('content')
<h1>{{ $ticket->subject }}</h1>
<div class="card">
    <dl class="facts">
        <dt>工單編號</dt><dd><span class="code">{{ $ticket->ticket_no }}</span></dd>
        <dt>類別</dt><dd>{{ $ticket->categoryLabel() }}</dd>
        @if ($ticket->order_no)
            <dt>相關訂單</dt><dd><a href="{{ route('shop.orders.show', $ticket->order_no) }}"><span class="code">{{ $ticket->order_no }}</span></a></dd>
        @endif
        <dt>狀態</dt><dd><strong>{{ $ticket->status === 'open' ? '等待客服回覆' : $ticket->statusLabel() }}</strong></dd>
    </dl>
</div>

<div class="card">
    <h2>往來紀錄</h2>
    <ol class="thread">
        @foreach ($ticket->messages as $message)
            <li class="thread-item {{ $message->author_type === 'admin' ? 'from-staff' : 'from-player' }}">
                <div class="thread-meta"><strong>{{ $message->authorLabel() }}</strong> · {{ $message->created_at->format('Y-m-d H:i') }}</div>
                <div class="thread-body">{{ $message->body }}</div>
            </li>
        @endforeach
    </ol>

    @if ($ticket->isClosed())
        <p class="muted">這張工單已結案。如果還有問題，請<a href="{{ route('shop.support.index') }}">另外提出新的問題</a>。</p>
    @else
        <form method="post" action="{{ route('shop.support.reply', $ticket) }}">
            @csrf
            <label for="body">補充說明</label>
            <textarea id="body" name="body" rows="4" maxlength="2000" required>{{ old('body') }}</textarea>
            @error('body')<p class="field-error">{{ $message }}</p>@enderror
            <div class="actions"><button type="submit">送出回覆</button></div>
        </form>
    @endif
</div>
<p><a href="{{ route('shop.support.index') }}">← 回到我的問題</a></p>
@endsection
