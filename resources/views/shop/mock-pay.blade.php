@extends('shop.layout')
@section('title', '模擬付款')
@section('content')
@php($method = old('method', in_array(request('method'), ['card', 'atm', 'cvs'], true) ? request('method') : 'card'))
<h1>模擬付款頁</h1>
<div class="card">
    <p class="warning">這是模擬金流，不會產生真實交易。請勿輸入真實的信用卡或銀行資料，信用卡只接受下方列出的測試卡號。</p>
    <dl class="facts">
        <dt>訂單編號</dt><dd><span class="code">{{ $order->order_no }}</span></dd>
        <dt>方案</dt><dd>{{ $order->package_name }}</dd>
        <dt>應付金額</dt><dd>{{ number_format($order->amount) }} 元</dd>
        <dt>可得點數</dt><dd>{{ number_format($order->points) }} 點</dd>
        <dt>付款期限</dt><dd>{{ $order->expires_at->format('Y-m-d H:i') }}</dd>
    </dl>
</div>

@if (! $payable)
    <div class="card">
        <p>這筆訂單目前是「{{ $order->playerStatusLabel() }}」，不能再付款。</p>
        <a class="button secondary" href="{{ route('shop.orders.show', $order->order_no) }}">查看訂單</a>
    </div>
@else
<div class="card pay-tabs">
    <input class="tab-radio" type="radio" name="pay-tab" id="tab-card" @checked($method === 'card' || $method === 'advanced')>
    <input class="tab-radio" type="radio" name="pay-tab" id="tab-atm" @checked($method === 'atm')>
    <input class="tab-radio" type="radio" name="pay-tab" id="tab-cvs" @checked($method === 'cvs')>
    <div class="tab-labels" role="tablist">
        <label for="tab-card" role="tab">信用卡</label>
        <label for="tab-atm" role="tab">ATM 虛擬帳號</label>
        <label for="tab-cvs" role="tab">超商代碼</label>
    </div>

    {{-- 信用卡 --}}
    <section class="pay-panel panel-card" aria-label="信用卡付款">
        <form method="post" action="{{ route('mock.pay.submit', $order->order_no) }}" class="pay-form" id="card-form" novalidate>
            @csrf
            <input type="hidden" name="method" value="card">
            <label for="card_name">持卡人姓名（英文，與卡片相同）</label>
            <input type="text" id="card_name" name="card_name" value="{{ old('card_name') }}" maxlength="50" autocomplete="off" spellcheck="false" placeholder="WANG XIAO MING" data-check="name">
            <p class="field-error" data-error-for="card_name">@error('card_name'){{ $message }}@enderror</p>

            <label for="card_number">卡號 <span class="card-brand" id="card-brand"></span></label>
            <input type="text" id="card_number" name="card_number" inputmode="numeric" maxlength="23" autocomplete="off" placeholder="0000 0000 0000 0000" data-check="number">
            <p class="field-error" data-error-for="card_number">@error('card_number'){{ $message }}@enderror</p>

            <div class="field-row">
                <div>
                    <label for="card_expiry">有效期限（MM/YY）</label>
                    <input type="text" id="card_expiry" name="card_expiry" value="{{ old('card_expiry') }}" inputmode="numeric" maxlength="5" autocomplete="off" placeholder="MM/YY" data-check="expiry">
                    <p class="field-error" data-error-for="card_expiry">@error('card_expiry'){{ $message }}@enderror</p>
                </div>
                <div>
                    <label for="card_cvv">安全碼（卡片背面）</label>
                    <input type="password" id="card_cvv" name="card_cvv" inputmode="numeric" maxlength="4" autocomplete="off" placeholder="3 碼" data-check="cvv">
                    <p class="field-error" data-error-for="card_cvv">@error('card_cvv'){{ $message }}@enderror</p>
                </div>
            </div>
            <div class="actions"><button type="submit">確認付款 {{ number_format($order->amount) }} 元</button></div>
        </form>

        <h2 class="test-cards-title">測試卡號</h2>
        <p class="muted">有效期限填未來的月份（例如 {{ now()->addYears(3)->format('m/y') }}），安全碼任意。付款結果由卡號決定。</p>
        <div class="table-wrap">
            <table class="test-cards">
                <thead><tr><th>卡號</th><th>卡別</th><th>結果</th><th></th></tr></thead>
                <tbody>
                @foreach ($cards as $number => [$brand, $scenario, $label])
                    <tr>
                        <td><span class="code">{{ \App\Payments\MockCheckout::group($number) }}</span></td>
                        <td>{{ $brands[$brand] }}</td>
                        <td>{{ $label }}</td>
                        <td><button type="button" class="secondary small" data-fill-card="{{ $number }}">填入</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <details class="advanced" @if ($method === 'advanced') open @endif>
            <summary>進階測試：模擬金流通知被竄改</summary>
            <p class="muted">不需要付款資料，直接送出一筆異常的 Webhook，用來測試系統是否會擋下。</p>
            <form method="post" action="{{ route('mock.pay.submit', $order->order_no) }}">
                @csrf
                <input type="hidden" name="method" value="advanced">
                <label for="scenario">異常情境</label>
                <select id="scenario" name="scenario">
                    @foreach ($advanced as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <div class="actions"><button type="submit" class="secondary">送出異常通知</button></div>
            </form>
        </details>
    </section>

    {{-- ATM 虛擬帳號 --}}
    <section class="pay-panel panel-atm" aria-label="ATM 虛擬帳號付款">
        <p>請在期限內以 ATM 或網路銀行轉帳到下面的虛擬帳號。虛擬帳號只對應這筆訂單，金額不符會轉帳失敗。</p>
        <dl class="facts">
            <dt>轉入銀行代碼</dt><dd><span class="code">{{ $atmBank }}</span>（模擬銀行）</dd>
            <dt>虛擬帳號</dt><dd><span class="code" id="atm-account">{{ trim(chunk_split($atmAccount, 4, ' ')) }}</span></dd>
            <dt>應繳金額</dt><dd>{{ number_format($order->amount) }} 元</dd>
            <dt>繳費期限</dt><dd>{{ $order->expires_at->format('Y-m-d H:i') }}</dd>
        </dl>
        <h2 class="sub">模擬 ATM 轉帳</h2>
        <form method="post" action="{{ route('mock.pay.submit', $order->order_no) }}" class="pay-form" novalidate>
            @csrf
            <input type="hidden" name="method" value="atm">
            <label for="atm_bank">轉入銀行代碼</label>
            <input type="text" id="atm_bank" name="atm_bank" value="{{ old('atm_bank') }}" inputmode="numeric" maxlength="3" autocomplete="off" data-check="digits" data-length="3" data-label="轉入銀行代碼">
            <p class="field-error" data-error-for="atm_bank">@error('atm_bank'){{ $message }}@enderror</p>
            <label for="atm_account">轉入帳號</label>
            <input type="text" id="atm_account" name="atm_account" value="{{ old('atm_account') }}" inputmode="numeric" maxlength="18" autocomplete="off" data-check="digits" data-length="14" data-label="轉入帳號">
            <p class="field-error" data-error-for="atm_account">@error('atm_account'){{ $message }}@enderror</p>
            <label for="atm_amount">轉帳金額（元）</label>
            <input type="text" id="atm_amount" name="atm_amount" value="{{ old('atm_amount') }}" inputmode="numeric" maxlength="9" autocomplete="off" data-check="amount" data-amount="{{ $order->amount }}" data-label="轉帳金額">
            <p class="field-error" data-error-for="atm_amount">@error('atm_amount'){{ $message }}@enderror</p>
            <div class="actions"><button type="submit">模擬轉帳</button></div>
        </form>
    </section>

    {{-- 超商代碼 --}}
    <section class="pay-panel panel-cvs" aria-label="超商代碼付款">
        @if (! $cvsAvailable)
            <p class="warning">超商代碼單筆上限 {{ number_format($cvsLimit) }} 元，這筆訂單請改用其他付款方式。</p>
        @else
            <p>請在期限內到超商的多媒體機輸入繳費代碼，列印繳費單後到櫃檯付款。</p>
            <dl class="facts">
                <dt>繳費代碼</dt><dd><span class="code">{{ $cvsCode }}</span></dd>
                <dt>應繳金額</dt><dd>{{ number_format($order->amount) }} 元</dd>
                <dt>繳費期限</dt><dd>{{ $order->expires_at->format('Y-m-d H:i') }}</dd>
                <dt>單筆上限</dt><dd>{{ number_format($cvsLimit) }} 元</dd>
            </dl>
            <h2 class="sub">模擬超商櫃檯繳費</h2>
            <form method="post" action="{{ route('mock.pay.submit', $order->order_no) }}" class="pay-form" novalidate>
                @csrf
                <input type="hidden" name="method" value="cvs">
                <label for="cvs_code">繳費代碼</label>
                <input type="text" id="cvs_code" name="cvs_code" value="{{ old('cvs_code') }}" maxlength="14" autocomplete="off" spellcheck="false" data-check="code" data-label="繳費代碼">
                <p class="field-error" data-error-for="cvs_code">@error('cvs_code'){{ $message }}@enderror</p>
                <label for="cvs_amount">繳費金額（元）</label>
                <input type="text" id="cvs_amount" name="cvs_amount" value="{{ old('cvs_amount') }}" inputmode="numeric" maxlength="9" autocomplete="off" data-check="amount" data-amount="{{ $order->amount }}" data-label="繳費金額">
                <p class="field-error" data-error-for="cvs_amount">@error('cvs_amount'){{ $message }}@enderror</p>
                <div class="actions"><button type="submit">模擬繳費</button></div>
            </form>
        @endif
    </section>
</div>
@endif
<script src="/mock-pay.js" defer></script>
@endsection
