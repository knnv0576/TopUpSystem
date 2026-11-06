@extends('game.layout')
@section('title', '遊戲紀錄')
@section('content')
<h1>遊戲紀錄</h1>
<div class="card" id="wheel-verify" data-segments="{{ json_encode(array_map(fn ($s) => ['weight' => $s['weight'], 'multiplier_pct' => $s['multiplier_pct']], config('minigame.wheel.segments'))) }}">
    <p class="muted">已結算的局可以按「驗證」，由你的瀏覽器重新計算雜湊與結果，不經過伺服器。</p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>時間</th><th class="num">下注</th><th>結果</th><th class="num">拿回</th><th>說明</th><th>公平性</th></tr></thead>
        <tbody>
        @forelse ($rounds as $round)
            @php
                $hasResult = in_array($round->status, ['payout_pending', 'settled'], true);
                $summary = $hasResult ? \App\MiniGame\Support\WheelOutcome::summary($round->bet_points, $round->payout_points) : null;
            @endphp
            <tr>
                <td>{{ ($round->spun_at ?? $round->updated_at)->format('Y-m-d H:i:s') }}</td>
                <td class="num">{{ $hasResult || $round->status === 'debit_pending' ? number_format($round->bet_points) : '—' }}</td>
                <td>{{ $hasResult ? \App\MiniGame\Support\WheelOutcome::segmentForRoll($round->roll)['label'] : '—' }}</td>
                <td class="num">{{ $hasResult ? number_format($round->payout_points) : '—' }}</td>
                <td>
                    @switch ($round->status)
                        @case('settled') {{ $summary['text'] }} @break
                        @case('payout_pending') {{ $summary['text'] }}（入帳中） @break
                        @case('debit_pending') 處理中 @break
                        @case('rejected') {{ $round->rejectMessage() }} @break
                        @case('integrity_hold') {{ \App\Support\PlayerMessages::ROUND_HELD }} @break
                    @endswitch
                </td>
                <td>
                    @if ($round->status === 'settled')
                        <button type="button" class="secondary" data-verify
                                data-public-id="{{ $round->public_id }}"
                                data-server-seed="{{ $round->server_seed }}"
                                data-hash="{{ $round->server_seed_hash }}"
                                data-client-seed="{{ $round->client_seed }}"
                                data-roll="{{ $round->roll }}"
                                data-multiplier="{{ $round->multiplier_pct }}">驗證</button>
                        <span data-verify-result role="status"></span>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">還沒有遊戲紀錄</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="pagination">{{ $rounds->links() }}</div>
</div>
<script src="/wheel.js" defer></script>
@endsection
