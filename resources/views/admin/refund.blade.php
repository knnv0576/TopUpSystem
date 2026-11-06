@extends('admin.layout')
@section('title', '退款申請 #'.$refund->id)
@php $inProgress = in_array($refund->status, ['pending_freeze', 'approved', 'points_deducted', 'paying_out', 'closing'], true); @endphp
@section('head')
    {{-- 核准後的扣點與撥款在背景執行，處理完成前每 2 秒自動更新。 --}}
    @if ($inProgress)<meta http-equiv="refresh" content="2">@endif
@endsection
@section('content')
@php $admin = auth('admin')->user(); @endphp
<h1>退款申請 #{{ $refund->id }}</h1>
@if ($inProgress)
    <p class="warning" role="status">背景處理中（{{ $refund->adminLabel() }}），頁面會自動更新。</p>
@endif
<div class="grid">
    <div class="card">
        <h2>申請內容</h2>
        <dl class="facts">
            <dt>狀態</dt><dd>{{ $refund->adminLabel() }} @if ($refund->isOverdue()) <span class="badge warn">審核逾時</span> @endif</dd>
            <dt>點數</dt><dd>{{ number_format($refund->points) }} 點</dd>
            <dt>金額</dt><dd>{{ number_format($refund->amount) }} 元（固定匯率，不可修改）</dd>
            <dt>撥款帳戶</dt><dd>{{ $refund->maskedAccount() }}@if ($refund->bank_label)（玩家綁定的「{{ $refund->bank_label }}」）@endif</dd>
            @if ($refund->holder_name_masked)<dt>戶名</dt><dd>{{ $refund->holder_name_masked }}（綁定時已向銀行驗證）</dd>@endif
            <dt>初審</dt><dd>{{ $refund->reviewer?->username ?? '—' }}</dd>
            <dt>覆核</dt><dd>{{ $refund->supervisor?->username ?? '—' }}</dd>
            <dt>理由</dt><dd>{{ $refund->reject_reason ?? '—' }}</dd>
            <dt>申請時間</dt><dd>{{ $refund->created_at->format('Y-m-d H:i:s') }}</dd>
        </dl>
    </div>
    <div class="card">
        <h2>玩家</h2>
        @if ($playerInfo)
            <dl class="facts">
                <dt>玩家編號</dt><dd><a href="{{ route('admin.players.show', $refund->player) }}">{{ $playerInfo['player_no'] }}</a></dd>
                <dt>暱稱</dt><dd>{{ $playerInfo['nickname'] }}</dd>
                <dt>餘額</dt><dd>{{ number_format($playerInfo['balance']) }} 點</dd>
                <dt>凍結</dt><dd>{{ number_format($playerInfo['frozen']) }} 點</dd>
                <dt>狀態</dt><dd>{{ $playerInfo['suspended'] ? '已停權' : '正常' }}</dd>
            </dl>
        @else
            <p class="muted">暫時無法取得玩家資料。</p>
        @endif
    </div>
</div>

@foreach ($anomalies as $anomaly)
    <div class="flash err">{{ $anomaly->kindLabel() }}：{{ $anomaly->detail }}</div>
@endforeach

@if ($refund->status === 'pending_review' && $admin->isReviewer())
    <div class="card">
        <h2>初審</h2>
        <form method="post" action="{{ route('admin.refunds.review-approve', $refund) }}" data-confirm="確定同意，交由覆核？">
            @csrf<button type="submit">同意，交由覆核</button>
        </form>
        <form method="post" action="{{ route('admin.refunds.review-reject', $refund) }}">
            @csrf
            <label for="reason">拒絕理由（玩家看得到）</label>
            <input id="reason" type="text" name="reason" maxlength="200" required>
            <div class="actions"><button class="secondary" type="submit">拒絕並解凍</button></div>
        </form>
    </div>
@elseif ($refund->status === 'pending_supervisor' && $admin->isSupervisor())
    <div class="card">
        <h2>覆核</h2>
        <form method="post" action="{{ route('admin.refunds.approve', $refund) }}" data-confirm="確定核准？核准後會立即扣點並撥款 {{ number_format($refund->amount) }} 元（模擬）。">
            @csrf
            <label for="code">TOTP 驗證碼</label>
            <input id="code" type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
            <div class="actions"><button type="submit">核准並送出</button></div>
        </form>
        <form method="post" action="{{ route('admin.refunds.reject', $refund) }}">
            @csrf
            <label for="reason">拒絕理由（玩家看得到）</label>
            <input id="reason" type="text" name="reason" maxlength="200" required>
            <div class="actions"><button class="secondary" type="submit">拒絕並解凍</button></div>
        </form>
    </div>
@elseif (in_array($refund->status, ['payout_failed', 'payout_returned'], true) && $admin->isSupervisor())
    <div class="card">
        @if ($refund->status === 'payout_returned')
            <h2>退匯處理</h2>
            <p class="warning">銀行已把這筆撥款退回公司帳戶（例如帳戶已結清或戶名不符）。帳號資料已在撥款時刪除，不能重新撥款；請結案把點數還給玩家，玩家可以改綁正確的帳戶後重新申請。</p>
        @else
            <h2>撥款失敗處理</h2>
            <form method="post" action="{{ route('admin.refunds.retry', $refund) }}">
                @csrf<button type="submit">重試撥款</button>
            </form>
        @endif
        <form method="post" action="{{ route('admin.refunds.close', $refund) }}" data-confirm="確定結案並把點數還給玩家？">
            @csrf
            <label for="reason">結案理由（玩家看得到）</label>
            <input id="reason" type="text" name="reason" maxlength="200" required>
            <label for="code">TOTP 驗證碼</label>
            <input id="code" type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
            <div class="actions"><button class="secondary" type="submit">結案並還點</button></div>
        </form>
    </div>
@endif

<div class="card">
    <h2>稽核紀錄</h2>
    @include('admin.partials.audit-table', ['logs' => $logs])
</div>
@endsection
