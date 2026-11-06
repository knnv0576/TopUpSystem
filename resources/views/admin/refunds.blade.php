@extends('admin.layout')
@section('title', '退款審核')
@section('content')
@php($me = auth('admin')->user())
<h1>退款審核</h1>
<div class="card">
    <form method="get" action="{{ route('admin.refunds') }}" class="actions">
        <div>
            <label for="status">狀態</label>
            <select id="status" name="status">
                <option value="">全部</option>
                @foreach (\App\Models\RefundRequest::ADMIN_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="secondary" type="submit">篩選</button>
    </form>
</div>
<div class="card">
    <div class="table-wrap">
    <table>
        <thead><tr><th>編號</th><th>遊戲玩家 ID</th><th class="num">點數</th><th class="num">金額</th><th>帳戶</th><th>狀態</th><th>申請時間</th><th class="num">操作</th></tr></thead>
        <tbody>
        @forelse ($refunds as $refund)
            <tr>
                <td><a href="{{ route('admin.refunds.show', $refund) }}">#{{ $refund->id }}</a></td>
                <td>{{ $refund->player->game_player_id }}</td>
                <td class="num">{{ number_format($refund->points) }}</td>
                <td class="num">{{ number_format($refund->amount) }} 元</td>
                <td>{{ $refund->maskedAccount() }}</td>
                <td>
                    {{ $refund->adminLabel() }}
                    @if ($refund->isOverdue()) <span class="badge warn">審核逾時</span> @endif
                </td>
                <td>{{ $refund->created_at->format('Y-m-d H:i') }}</td>
                <td class="row-action">
                    {{-- 依目前角色顯示輪到自己做的動作；實際核准、拒絕仍在詳細頁進行（覆核需要 TOTP）。 --}}
                    @php($action = match (true) {
                        $refund->status === 'pending_review' && $me->isReviewer() => '初審',
                        $refund->status === 'pending_supervisor' && $me->isSupervisor() => '覆核',
                        $refund->status === 'payout_failed' && $me->isSupervisor() => '處理撥款失敗',
                        $refund->status === 'payout_returned' && $me->isSupervisor() => '處理退匯',
                        default => null,
                    })
                    @if ($action)
                        <a class="button" href="{{ route('admin.refunds.show', $refund) }}">{{ $action }}</a>
                    @else
                        <a class="button secondary" href="{{ route('admin.refunds.show', $refund) }}">查看</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">沒有資料</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="pagination">{{ $refunds->links('pagination::simple-tailwind') }}</div>
</div>
@endsection
