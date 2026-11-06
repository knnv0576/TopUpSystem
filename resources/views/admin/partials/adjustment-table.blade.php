@php
    $statusLabels = ['pending_supervisor' => '待核准', 'applied' => '已生效', 'rejected' => '已拒絕'];
    $canDecide = auth('admin')->user()->isSupervisor();
@endphp
<div class="table-wrap">
<table>
    <thead><tr><th>編號</th>@if ($showPlayer)<th>玩家</th>@endif<th>方向</th><th class="num">點數</th><th>理由</th><th>發起者</th><th>核准者</th><th>狀態</th><th></th></tr></thead>
    <tbody>
    @forelse ($adjustments as $adjustment)
        <tr>
            <td>#{{ $adjustment->id }}</td>
            @if ($showPlayer)<td><a href="{{ route('admin.players.show', $adjustment->player) }}">遊戲 ID {{ $adjustment->player->game_player_id }}</a></td>@endif
            <td>{{ $adjustment->direction === 'add' ? '增加' : '扣除' }}</td>
            <td class="num">{{ number_format($adjustment->points) }}</td>
            <td>{{ $adjustment->reason }}</td>
            <td>{{ $adjustment->initiator->username }}</td>
            <td>{{ $adjustment->supervisor?->username ?? '—' }}</td>
            <td>{{ $statusLabels[$adjustment->status] ?? $adjustment->status }}@if ($adjustment->reject_reason)：{{ $adjustment->reject_reason }}@endif</td>
            <td>
                @if ($canDecide && $adjustment->status === 'pending_supervisor')
                    <form method="post" action="{{ route('admin.adjustments.approve', $adjustment) }}">
                        @csrf
                        <input type="text" name="code" placeholder="TOTP" aria-label="TOTP 驗證碼" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
                        <button type="submit">核准</button>
                    </form>
                    <form method="post" action="{{ route('admin.adjustments.reject', $adjustment) }}">
                        @csrf
                        <input type="text" name="reason" placeholder="拒絕理由" aria-label="拒絕理由" maxlength="200" required>
                        <button class="secondary" type="submit">拒絕</button>
                    </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="{{ $showPlayer ? 9 : 8 }}" class="muted">沒有資料</td></tr>
    @endforelse
    </tbody>
</table>
</div>
