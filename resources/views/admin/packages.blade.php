@extends('admin.layout')
@section('title', '方案管理')
@section('content')
@php $canEdit = auth('admin')->user()->isSupervisor(); @endphp
<h1>方案管理</h1>
<div class="card">
    <p class="muted">點數由固定匯率算出（1 元 = 100 點）。方案不能刪除；修改不影響已成立的訂單。新增與修改都要輸入 TOTP 驗證碼，稽核紀錄會保留修改前後的值。</p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>名稱</th><th class="num">價格</th><th class="num">點數</th><th>狀態</th>@if ($canEdit)<th>修改</th>@endif</tr></thead>
        <tbody>
        @foreach ($packages as $package)
            <tr>
                <td>{{ $package->name }}</td>
                <td class="num">{{ number_format($package->price) }} 元</td>
                <td class="num">{{ number_format($package->points()) }}</td>
                <td>{{ $package->active ? '上架' : '下架' }}</td>
                @if ($canEdit)
                <td>
                    <form method="post" action="{{ route('admin.packages.update', $package) }}">
                        @csrf
                        <input type="text" name="name" value="{{ $package->name }}" aria-label="名稱" maxlength="50" required>
                        <input type="number" name="price" value="{{ $package->price }}" aria-label="價格" min="1" max="1000000" required>
                        <label><input type="checkbox" name="active" value="1" @checked($package->active)> 上架</label>
                        <input type="text" name="code" placeholder="TOTP" aria-label="TOTP 驗證碼" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
                        <button class="secondary" type="submit">儲存</button>
                    </form>
                </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@if ($canEdit)
    <div class="card">
        <h2>新增方案</h2>
        <form method="post" action="{{ route('admin.packages.store') }}">
            @csrf
            <label for="name">名稱</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" maxlength="50" required>
            <label for="price">價格（元）</label>
            <input id="price" type="number" name="price" value="{{ old('price') }}" min="1" max="1000000" required>
            <label for="code">TOTP 驗證碼</label>
            <input id="code" type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
            <div class="actions"><button type="submit">新增</button></div>
        </form>
    </div>
@endif
@endsection
