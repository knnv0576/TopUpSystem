@extends('game.layout')
@section('title', '退款')
@php($freezing = collect($refunds)->where('freezing', true)->sum('points'))
@section('head')
    @if ($freezing > 0)
        <meta http-equiv="refresh" content="2">
    @endif
@endsection
@section('content')
<h1>退款</h1>
@if ($freezing > 0)
    <p class="warning" role="status">有 {{ number_format($freezing) }} 點正在凍結，完成後可用點數會更新，頁面會自動重新整理。</p>
@endif
<div class="card">
    <h2>申請退款</h2>
    <p class="muted">可用點數 {{ number_format($wallet->available()) }} 點。100 點 = 1 元；點數須為 100 的倍數，單次至少 10,000 點（100 元）。</p>
    <p class="warning">這是模擬撥款，不會有真實匯款。請勿填寫真實銀行帳號。</p>
    <form method="post" action="{{ route('game.refunds.submit') }}" data-dialog="#refund-dialog">
        @csrf
        <input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}">
        <input type="hidden" name="acknowledged" value="">
        <label for="points">退款點數</label>
        <input id="points" type="number" name="points" min="10000" step="100" value="{{ old('points') }}" required>
        @error('points')<p class="field-error">{{ $message }}</p>@enderror
        <fieldset class="account-pick">
            <legend>撥款到哪個帳戶</legend>
            @forelse ($accounts as $account)
                @php($firstUsable = collect($accounts)->firstWhere('returned', false))
                <label class="account-option {{ $account['returned'] ? 'is-disabled' : '' }}">
                    <input type="radio" name="bank_account_id" value="{{ $account['id'] }}" required @disabled($account['returned'])
                           @checked(! $account['returned'] && (int) old('bank_account_id', $firstUsable['id'] ?? 0) === $account['id'])>
                    <span><strong>{{ $account['label'] }}</strong><span class="muted">{{ $account['masked'] }}@if ($account['holder'])｜戶名 {{ $account['holder'] }}@endif</span>@if ($account['returned'])<span class="badge err">曾被退匯，請刪除後重新綁定</span>@endif</span>
                </label>
            @empty
                <p class="muted">還沒有綁定撥款帳戶，請先在下方「撥款帳戶」新增。</p>
            @endforelse
        </fieldset>
        @error('bank_account_id')<p class="field-error">{{ $message }}</p>@enderror
        <p class="muted">審核通過後會直接撥款到選定的帳戶。送出後即使刪除這個綁定，這筆申請仍會撥到原本選的帳戶。</p>
        <div class="actions"><button type="submit" @disabled(collect($accounts)->where('returned', false)->isEmpty())>送出申請</button></div>
    </form>
</div>

<div class="card" id="bank-accounts">
    <h2>撥款帳戶</h2>
    <p class="muted">最多綁定 {{ \App\Models\PlayerBankAccount::MAX_PER_PLAYER }} 個。帳號以加密方式保存，畫面只顯示銀行代碼與後四碼。</p>
    @error('bank_account')<p class="field-error">{{ $message }}</p>@enderror
    <div class="table-wrap">
    <table>
        <thead><tr><th>名稱</th><th>帳戶</th><th>戶名</th><th>狀態</th><th>綁定時間</th><th class="num">操作</th></tr></thead>
        <tbody>
        @forelse ($accounts as $account)
            <tr>
                <td>{{ $account['label'] }}</td>
                <td><span class="code">{{ $account['masked'] }}</span></td>
                <td>{{ $account['holder'] ?? '—' }}</td>
                <td>
                    @if ($account['returned'])<span class="badge err">曾被退匯</span>
                    @elseif ($account['verified'])<span class="badge ok">戶名已驗證</span>
                    @else<span class="badge">未驗證</span>@endif
                </td>
                <td>{{ \Illuminate\Support\Carbon::parse($account['created_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                <td class="row-action">
                    <form class="inline" method="post" action="{{ route('game.refunds.accounts.destroy', $account['id']) }}" data-confirm="確定刪除「{{ $account['label'] }}」這個撥款帳戶？">
                        @csrf
                        <button type="submit" class="secondary">刪除</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">還沒有綁定撥款帳戶</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>

    @if (count($accounts) < \App\Models\PlayerBankAccount::MAX_PER_PLAYER)
        <details class="add-account" @if ($errors->hasAny(['label', 'bank_code', 'bank_account', 'password']) || empty($accounts)) open @endif>
            <summary>新增撥款帳戶</summary>
            <form method="post" action="{{ route('game.refunds.accounts.store') }}">
                @csrf
                <label for="label">帳戶名稱（自己看得懂就好，例如「薪轉帳戶」）</label>
                <input id="label" type="text" name="label" value="{{ old('label') }}" maxlength="30" required>
                @error('label')<p class="field-error">{{ $message }}</p>@enderror
                <label for="bank_code">銀行代碼（3 碼）</label>
                <input id="bank_code" type="text" name="bank_code" value="{{ old('bank_code') }}" inputmode="numeric" pattern="\d{3}" maxlength="3" autocomplete="off" required>
                @error('bank_code')<p class="field-error">{{ $message }}</p>@enderror
                <label for="bank_account">帳號（6–16 碼）</label>
                <input id="bank_account" type="text" name="bank_account" inputmode="numeric" pattern="\d{6,16}" maxlength="16" autocomplete="off" required>
                <label for="holder_name">戶名（與銀行帳戶相同）</label>
                <input id="holder_name" type="text" name="holder_name" value="{{ old('holder_name') }}" maxlength="50" autocomplete="off" required>
                @error('holder_name')<p class="field-error">{{ $message }}</p>@enderror
                <p class="muted">綁定時會向銀行確認帳號存在、戶名相符。模擬銀行的測試規則：帳號結尾 <span class="code">9999</span> 查無帳號、<span class="code">8888</span> 戶名不符、<span class="code">7777</span> 可以綁定但撥款後會被退匯，其他帳號都會通過。</p>
                <label for="account_password">目前的密碼（確認是本人操作）</label>
                <input id="account_password" type="password" name="password" autocomplete="current-password" required>
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
                <p class="warning">這是模擬撥款，請勿填寫真實銀行帳號。</p>
                <div class="actions"><button type="submit">綁定帳戶</button></div>
            </form>
        </details>
    @endif
</div>
<dialog id="refund-dialog">
    <h2>確認送出退款申請</h2>
    <p>送出後無法撤銷，申請的點數會立即凍結到審核完成。請勿填寫真實銀行帳號。</p>
    <div class="actions">
        <button type="button" data-dialog-ok>確認送出</button>
        <button type="button" class="secondary" data-dialog-cancel>返回修改</button>
    </div>
</dialog>
<div class="card">
    <h2>退款紀錄</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>編號</th><th class="num">點數</th><th class="num">金額</th><th>撥款帳戶</th><th>進度</th><th>理由</th><th>申請時間</th></tr></thead>
        <tbody>
        @forelse ($refunds as $refund)
            <tr>
                <td>#{{ $refund['id'] }}</td>
                <td class="num">{{ number_format($refund['points']) }}</td>
                <td class="num">{{ number_format($refund['amount']) }} 元</td>
                <td>{{ $refund['account'] }}@if (! empty($refund['account_label']))<br><span class="muted">{{ $refund['account_label'] }}</span>@endif</td>
                <td>{{ $refund['status'] }}</td>
                <td>{{ $refund['reason'] }}</td>
                <td>{{ \Illuminate\Support\Carbon::parse($refund['created_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">還沒有申請紀錄</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
