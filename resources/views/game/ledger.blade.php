@extends('game.layout')
@section('title', '點數明細')
@section('content')
@php
    $typeLabels = [
        'topup' => '充值入點', 'task' => '完成任務', 'game_debit' => '遊戲扣點', 'game_credit' => '遊戲獎勵', 'game_debit_void' => '扣點作廢','admin_adjust' => '人工調整',
        'red_packet_send' => '發送紅包', 'red_packet_claim' => '領取紅包', 'red_packet_return' => '紅包退回',
        'refund_freeze' => '退款凍結', 'refund_unfreeze' => '退款解凍', 'refund_deduct' => '退款扣點', 'refund_return' => '結案還點',
    ];
@endphp
<h1>點數明細</h1>
<div class="card">
    <p>餘額 {{ number_format($wallet->balance) }} 點，凍結 {{ number_format($wallet->frozen) }} 點，可用 {{ number_format($wallet->available()) }} 點。</p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>時間</th><th>類型</th><th class="num">餘額異動</th><th class="num">凍結異動</th><th class="num">異動後餘額</th><th>說明</th></tr></thead>
        <tbody>
        @forelse ($entries as $entry)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('Y-m-d H:i:s') }}</td>
                <td>{{ $typeLabels[$entry->type] ?? $entry->type }}</td>
                <td class="num">{{ $entry->delta_balance > 0 ? '+' : '' }}{{ number_format($entry->delta_balance) }}</td>
                <td class="num">{{ $entry->delta_frozen > 0 ? '+' : '' }}{{ number_format($entry->delta_frozen) }}</td>
                <td class="num">{{ number_format($entry->balance_after) }}</td>
                <td>{{ $entry->memo }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">還沒有紀錄</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="pagination">{{ $entries->links('pagination::simple-tailwind') }}</div>
</div>
@endsection
