@extends('admin.layout')
@section('title', '管理員帳號')
@section('content')
@php $me = auth('admin')->user(); @endphp
<h1>管理員帳號</h1>
<div class="card">
    <p class="muted">定期檢視這份清單，停用不再需要的帳號。忘記密碼或遺失 TOTP 時，由主機管理者執行 <span class="code">php artisan topup:admin-reset</span>。</p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>帳號</th><th>角色</th><th>狀態</th><th>TOTP</th><th>上次登入</th><th>密碼更新</th><th>操作</th></tr></thead>
        <tbody>
        @foreach ($accounts as $account)
            <tr>
                <td>{{ $account->username }}</td>
                <td>{{ $account->roleLabel() }}</td>
                <td>
                    @if ($account->isDisabled())
                        <span class="badge err">已停用</span>
                    @elseif ($account->isLockedOut())
                        <span class="badge warn">鎖定至 {{ $account->locked_until->format('H:i') }}</span>
                    @elseif ($account->must_change_password)
                        <span class="badge warn">待更改初始密碼</span>
                    @else
                        <span class="badge ok">正常</span>
                    @endif
                </td>
                <td>{{ $account->totp_confirmed_at ? '已綁定' : '未綁定' }}</td>
                <td>{{ $account->last_login_at?->format('Y-m-d H:i') ?? '—' }}</td>
                <td>{{ $account->password_changed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                <td>
                    @unless ($account->is($me))
                        <form method="post" action="{{ route($account->isDisabled() ? 'admin.accounts.enable' : 'admin.accounts.disable', $account) }}" data-confirm="確定{{ $account->isDisabled() ? '恢復' : '停用' }} {{ $account->username }}？">
                            @csrf
                            <input type="text" name="reason" placeholder="理由" aria-label="理由" maxlength="200" required>
                            <input type="text" name="code" placeholder="TOTP" aria-label="TOTP 驗證碼" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
                            <button class="secondary" type="submit">{{ $account->isDisabled() ? '恢復' : '停用' }}</button>
                        </form>
                    @endunless
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endsection
