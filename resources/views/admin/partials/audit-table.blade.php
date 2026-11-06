<div class="table-wrap">
<table>
    <thead><tr><th>時間</th><th>操作者</th><th>動作</th><th>對象</th><th>狀態變更</th><th class="num">金額</th><th class="num">點數</th><th>說明</th><th>來源 IP</th></tr></thead>
    <tbody>
    @forelse ($logs as $log)
        <tr>
            <td>{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
            <td>{{ $log->actor ?? $log->actor_type }}</td>
            <td>{{ $log->action }}</td>
            <td>{{ $log->subject_type }}{{ $log->subject_id ? ' #'.$log->subject_id : '' }}</td>
            <td>{{ $log->from_status }}{{ $log->from_status || $log->to_status ? ' → ' : '' }}{{ $log->to_status }}</td>
            <td class="num">{{ $log->amount !== null ? number_format($log->amount) : '' }}</td>
            <td class="num">{{ $log->points !== null ? number_format($log->points) : '' }}</td>
            <td>{{ $log->note }}</td>
            <td>{{ $log->ip }}</td>
        </tr>
    @empty
        <tr><td colspan="9" class="muted">沒有資料</td></tr>
    @endforelse
    </tbody>
</table>
</div>
