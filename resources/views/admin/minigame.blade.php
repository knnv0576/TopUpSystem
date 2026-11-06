@extends('admin.layout')
@section('title', '小遊戲')
@section('content')
<h1>小遊戲</h1>

@if ($stats['counts']['integrity_hold'] ?? 0)
    <div class="flash err" role="alert">有 {{ $stats['counts']['integrity_hold'] }} 局檢查碼不符，已停止自動處理，請查看下方「暫停的局」。</div>
@endif
@if ($stats['overdue'])
    <div class="flash err" role="alert">有 {{ $stats['overdue'] }} 局超過 {{ config('minigame.alert_after_minutes') }} 分鐘仍未完成，排程會持續重試。</div>
@endif

<div class="grid">
    <div class="card">
        <h2>帳務核對</h2>
        @if ($check['ok'])
            <p><span class="badge ok">核對通過</span></p>
        @else
            <p><span class="badge err">發現 {{ count($check['issues']) }} 項不一致</span></p>
            <ul>
                @foreach ($check['issues'] as $issue)
                    <li>{{ $issue }}</li>
                @endforeach
            </ul>
        @endif
        <dl class="facts">
            @foreach ($check['checked'] as $name => $count)
                <dt>{{ $name }}</dt><dd>{{ number_format($count) }} 筆</dd>
            @endforeach
            <dt>處理中的局</dt><dd>{{ number_format($check['in_progress']) }} 筆（不算缺帳）</dd>
            <dt>暫停的局</dt><dd>{{ number_format($check['held']) }} 筆</dd>
        </dl>
        <p class="muted">只讀取與比對，不會修改資料。也可以執行 <span class="code">php artisan minigame:check</span>。</p>
    </div>

    <div class="card">
        <h2>轉盤狀態</h2>
        <p>目前：@if ($enabled)<span class="badge ok">啟用中</span>@else<span class="badge err">已停用</span>@endif</p>
        @if (auth('admin')->user()->isSupervisor())
            <p class="muted">停用只擋新的轉盤，已開始的局會繼續完成。</p>
            <form method="post" action="{{ route('admin.minigame.toggle') }}" data-confirm="確定{{ $enabled ? '停用' : '啟用' }}轉盤？">
                @csrf
                <input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}">
                <label for="toggle_reason">理由</label>
                <input id="toggle_reason" type="text" name="reason" maxlength="200" required>
                <label for="code">驗證碼（TOTP）</label>
                <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                <div class="actions"><button type="submit">{{ $enabled ? '停用轉盤' : '啟用轉盤' }}</button></div>
            </form>
        @else
            <p class="muted">只有覆核者可以切換。</p>
        @endif
    </div>
</div>

<div class="card">
    <h2>統計（只計已結算的局）</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>檔位</th><th class="num">局數</th><th class="num">下注總額</th><th class="num">獎勵總額</th><th class="num">實際回報率</th><th class="num">設定值</th></tr></thead>
        <tbody>
        @foreach ($stats['rows'] as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="num">{{ number_format($row['rounds']) }}</td>
                <td class="num">{{ number_format($row['bet_total']) }}</td>
                <td class="num">{{ number_format($row['payout_total']) }}</td>
                <td class="num">{{ $row['bet_total'] > 0 ? number_format($row['payout_total'] / $row['bet_total'] * 100, 2).'%' : '—' }}</td>
                <td class="num">{{ $stats['target'] }}%</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    <p class="muted">局數少時實際回報率會有大幅波動，要一併看局數。其他狀態：
        @foreach (['debit_pending', 'payout_pending', 'rejected', 'integrity_hold'] as $status)
            {{ $statusLabels[$status] }} {{ number_format($stats['counts'][$status] ?? 0) }} 筆@unless ($loop->last)、@endunless
        @endforeach
    </p>
</div>

@if ($held)
<div class="card">
    <h2>暫停的局</h2>
    <p class="warning">局本身不會再變動，保留作為證據。需要補償時，請由初審到玩家頁面發起人工調整、覆核者核准，並在理由註明局號。</p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>局號</th><th>玩家</th><th class="num">下注</th><th class="num">獎勵</th><th>帳本實際紀錄</th></tr></thead>
        <tbody>
        @foreach ($held as $item)
            <tr>
                <td><span class="code">{{ $item['round']->public_id }}</span></td>
                <td>{{ $item['round']->player_no }}</td>
                <td class="num">{{ $item['round']->bet_points === null ? '—' : number_format($item['round']->bet_points) }}</td>
                <td class="num">{{ $item['round']->payout_points === null ? '—' : number_format($item['round']->payout_points) }}</td>
                <td>
                    @forelse ($item['ledger'] as $entry)
                        {{ ['game_debit' => '已扣點', 'game_credit' => '已發獎', 'game_debit_void' => '扣點已作廢'][$entry->type] ?? $entry->type }} {{ number_format($entry->delta_balance) }}（{{ $entry->created_at }}）<br>
                    @empty
                        沒有扣點或發獎紀錄
                    @endforelse
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endif

<div class="card">
    <h2>局紀錄</h2>
    <form method="get" action="{{ route('admin.minigame') }}" class="filters">
        <label for="player_no">玩家編號</label>
        <input id="player_no" type="text" name="player_no" maxlength="12" value="{{ $filters['player_no'] ?? '' }}">
        <label for="status">狀態</label>
        <select id="status" name="status">
            <option value="">全部</option>
            @foreach ($statusLabels as $value => $label)
                @continue ($value === 'committed')
                <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <div class="actions"><button type="submit" class="secondary">篩選</button></div>
    </form>
    <div class="table-wrap">
    <table>
        <thead><tr><th>時間</th><th>玩家</th><th>狀態</th><th class="num">下注</th><th class="num">倍率</th><th class="num">獎勵</th><th>局號與種子</th></tr></thead>
        <tbody>
        @forelse ($rounds as $round)
            <tr>
                <td>{{ ($round->spun_at ?? $round->updated_at)->format('Y-m-d H:i:s') }}</td>
                <td>{{ $round->player_no }}</td>
                <td>{{ $round->statusLabel() }}@if ($round->isOverdue()) <span class="badge err">逾時</span>@endif</td>
                <td class="num">{{ $round->bet_points === null ? '—' : number_format($round->bet_points) }}</td>
                <td class="num">{{ $round->multiplier_pct === null ? '—' : ($round->multiplier_pct / 100).' 倍' }}</td>
                <td class="num">{{ $round->payout_points === null ? '—' : number_format($round->payout_points) }}</td>
                <td class="seeds">
                    <span class="code">{{ $round->public_id }}</span><br>
                    @if ($round->status === 'settled')
                        種子 <span class="code">{{ $round->server_seed }}</span>
                    @else
                        雜湊 <span class="code">{{ $round->server_seed_hash }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">沒有符合的紀錄</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="pagination">{{ $rounds->links() }}</div>
</div>

<div class="card">
    <h2>遊戲內充值</h2>
    <p>
        @if (($filters['topup'] ?? null) === 'failed')
            <a href="{{ route('admin.minigame') }}">顯示全部</a>
        @else
            <a href="{{ route('admin.minigame', ['topup' => 'failed']) }}">只看失敗</a>
        @endif
    </p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>時間</th><th>訂單編號</th><th>玩家</th><th>方案</th><th class="num">金額</th><th class="num">點數</th><th>實際狀態</th></tr></thead>
        <tbody>
        @forelse ($topups as $order)
            <tr>
                <td>{{ $order->created_at->format('Y-m-d H:i:s') }}</td>
                <td><span class="code">{{ $order->order_no }}</span></td>
                <td><a href="{{ route('admin.players.show', $order->player) }}">#{{ $order->player->game_player_id }}</a></td>
                <td>{{ $order->package_name }}</td>
                <td class="num">{{ number_format($order->amount) }}</td>
                <td class="num">{{ number_format($order->points) }}</td>
                <td>{{ $order->statusLabel() }}@if ($order->failedForPlayer()) <span class="badge err">玩家看到失敗</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">沒有符合的紀錄</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="pagination">{{ $topups->links() }}</div>
</div>
@endsection
