@extends('admin.layout')
@section('title', '異常清單')
@section('content')
@php
    $canAct = auth('admin')->user()->isSupervisor();
    $retryable = ['grant_failed', 'grant_held_suspended', 'unfreeze_failed', 'suspension_sync_failed'];
    $needsTotp = ['grant_failed', 'grant_held_suspended'];
@endphp
<h1>異常清單</h1>
<div class="card">
    <p><a href="{{ route('admin.anomalies', $showResolved ? [] : ['resolved' => 1]) }}">{{ $showResolved ? '只看未處理' : '包含已處理' }}</a></p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>時間</th><th>類型</th><th>對象</th><th>內容</th><th>處理</th></tr></thead>
        <tbody>
        @forelse ($anomalies as $anomaly)
            <tr>
                <td>{{ $anomaly->created_at->format('Y-m-d H:i') }}</td>
                <td>{{ $anomaly->kindLabel() }}</td>
                <td>
                    @if ($anomaly->subject_type === 'RefundRequest')
                        <a href="{{ route('admin.refunds.show', $anomaly->subject_id) }}">退款 #{{ $anomaly->subject_id }}</a>
                    @elseif ($anomaly->subject_type === 'Player')
                        <a href="{{ route('admin.players.show', $anomaly->subject_id) }}">玩家 #{{ $anomaly->subject_id }}</a>
                    @else
                        {{ $anomaly->subject_type }} #{{ $anomaly->subject_id }}
                    @endif
                </td>
                <td>{{ $anomaly->detail }}</td>
                <td>
                    @if ($anomaly->resolved_at)
                        已處理：{{ $anomaly->resolution_note }}
                    @elseif ($canAct)
                        @if (in_array($anomaly->kind, $retryable, true))
                            <form method="post" action="{{ route('admin.anomalies.retry', $anomaly) }}">
                                @csrf
                                @if (in_array($anomaly->kind, $needsTotp, true))
                                    <input type="text" name="code" placeholder="TOTP" aria-label="TOTP 驗證碼" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
                                @endif
                                <button type="submit">{{ in_array($anomaly->kind, $needsTotp, true) ? '補入點' : '重新執行' }}</button>
                            </form>
                        @endif
                        <form method="post" action="{{ route('admin.anomalies.resolve', $anomaly) }}">
                            @csrf
                            <input type="text" name="note" placeholder="處理說明" aria-label="處理說明" maxlength="200" required>
                            <button class="secondary" type="submit">標記已處理</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">目前沒有異常</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="pagination">{{ $anomalies->links('pagination::simple-tailwind') }}</div>
</div>
@endsection
