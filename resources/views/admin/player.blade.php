@extends('admin.layout')
@section('title', '玩家資料')
@section('content')
@php $admin = auth('admin')->user(); @endphp
<h1>玩家 {{ $info['player_no'] ?? '#'.$player->game_player_id }}</h1>
<div class="grid">
    <div class="card">
        <h2>基本資料</h2>
        <dl class="facts">
            <dt>暱稱</dt><dd>{{ $info['nickname'] ?? '—' }}</dd>
            <dt>餘額</dt><dd>{{ number_format($info['balance'] ?? 0) }} 點</dd>
            <dt>凍結</dt><dd>{{ number_format($info['frozen'] ?? 0) }} 點</dd>
            <dt>可用</dt><dd>{{ number_format(($info['balance'] ?? 0) - ($info['frozen'] ?? 0)) }} 點</dd>
            <dt>狀態</dt><dd>
                @if ($player->isSuspended())
                    <span class="badge err">已停權</span> {{ $player->suspend_reason }}
                @else
                    正常
                @endif
            </dd>
        </dl>
    </div>
    @if ($admin->isReviewer())
        <div class="card">
            <h2>發起人工調整</h2>
            <p class="muted">發起後由覆核者核准才會生效。</p>
            <form method="post" action="{{ route('admin.players.adjust', $player) }}">
                @csrf
                <label for="direction">方向</label>
                <select id="direction" name="direction"><option value="add">增加點數</option><option value="deduct">扣除點數</option></select>
                <label for="points">點數</label>
                <input id="points" type="number" name="points" min="1" step="1" required>
                <label for="adjust_reason">理由</label>
                <input id="adjust_reason" type="text" name="reason" maxlength="200" required>
                <div class="actions"><button type="submit">發起</button></div>
            </form>
        </div>
    @endif
    @if ($admin->isSupervisor())
        <div class="card">
            @if ($player->isSuspended())
                <h2>解除停權</h2>
                <p class="muted">只恢復登入；已拒絕的申請與調整、已退回的紅包不會復原。暫扣的入點請到異常清單補入。</p>
                <form method="post" action="{{ route('admin.players.unsuspend', $player) }}">
            @else
                <h2>停權</h2>
                <p class="warning">玩家立即無法登入；審核中的退款與待核准的調整會被拒絕，未領紅包會退回。</p>
                <form method="post" action="{{ route('admin.players.suspend', $player) }}" data-confirm="確定停權這位玩家？">
            @endif
                @csrf
                <label for="suspend_reason">理由</label>
                <input id="suspend_reason" type="text" name="reason" maxlength="200" required>
                <label for="code">TOTP 驗證碼</label>
                <input id="code" type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
                <div class="actions"><button type="submit">{{ $player->isSuspended() ? '解除停權' : '停權' }}</button></div>
            </form>
        </div>
    @endif
</div>
<div class="card">
    <h2>人工調整紀錄</h2>
    @include('admin.partials.adjustment-table', ['adjustments' => $adjustments, 'showPlayer' => false])
</div>
@endsection
