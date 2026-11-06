@extends('admin.layout')
@section('title', '退款審核')
@section('content')
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
        <thead><tr><th>編號</th><th>遊戲玩家 ID</th><th class="num">點數</th><th class="num">金額</th><th>帳戶</th><th>狀態</th><th>申請時間</th></tr></thead>
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
            </tr>
        @empty
            <tr><td colspan="7" class="muted">沒有資料</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="pagination">{{ $refunds->links('pagination::simple-tailwind') }}</div>
</div>
@endsection
