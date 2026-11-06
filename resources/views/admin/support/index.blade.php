@extends('admin.layout')
@section('title', '客服工單')
@section('content')
<h1>客服工單</h1>
<div class="card">
    <p class="muted">通知信寄到 {{ config('topup.support_inbox') }}，可在本機信箱 <a href="http://localhost:8025" target="_blank" rel="noopener">Mailpit（localhost:8025）</a> 查看。</p>
    <p>
        @foreach (['open' => '待處理', 'answered' => '已回覆', 'closed' => '已結案', 'all' => '全部'] as $value => $label)
            @if ($status === $value)
                <strong>{{ $label }}</strong>
            @else
                <a href="{{ route('admin.support', ['status' => $value]) }}">{{ $label }}</a>
            @endif
            @if ($value !== 'all')（{{ $counts[$value] ?? 0 }}）@endif
            @if (! $loop->last)｜@endif
        @endforeach
    </p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>工單編號</th><th>類別</th><th>主旨</th><th>玩家</th><th>訂單</th><th>狀態</th><th>最後更新</th></tr></thead>
        <tbody>
        @forelse ($tickets as $ticket)
            <tr>
                <td><a href="{{ route('admin.support.show', $ticket) }}"><span class="code">{{ $ticket->ticket_no }}</span></a></td>
                <td>{{ $ticket->categoryLabel() }}</td>
                <td>{{ $ticket->subject }}</td>
                <td><a href="{{ route('admin.players.show', $ticket->player_id) }}">玩家 #{{ $ticket->player->game_player_id }}</a></td>
                <td>@if ($ticket->order_no)<span class="code">{{ $ticket->order_no }}</span>@endif</td>
                <td><span class="badge {{ $ticket->status === 'open' ? 'warn' : ($ticket->status === 'answered' ? 'ok' : '') }}">{{ $ticket->statusLabel() }}</span></td>
                <td>{{ $ticket->last_message_at->format('Y-m-d H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">沒有工單</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="pagination">{{ $tickets->links() }}</div>
</div>
@endsection
