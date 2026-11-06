@extends('shop.layout')
@section('title', '點數儲值')
@section('content')
@php
    $player = auth('shop')->user();
    $selected = (int) old('package_id', $packages->first()?->id);
    $selectedMethod = old('method', 'card');
    $methods = [
        'card' => ['信用卡', '國際信用卡、金融卡', true],
        'atm' => ['ATM 虛擬帳號', '取得專屬帳號後轉帳', true],
        'cvs' => ['超商代碼', '單筆上限 '.number_format(\App\Payments\MockCheckout::CVS_LIMIT).' 元', true],
    ];
@endphp
<form method="post" action="{{ route('shop.orders.create') }}" class="topup-layout" id="topup-form">
    @csrf
    <div class="topup-main">
        {{-- 商品資訊 --}}
        <section class="card topup-hero">
            <div class="topup-icon" aria-hidden="true"><span>P</span></div>
            <div>
                <h1 class="topup-title">DEMO 點數儲值</h1>
                <ul class="topup-chips">
                    <li><span class="chip-icon chip-fast" aria-hidden="true"></span>付款成功即入點</li>
                    <li><span class="chip-icon chip-safe" aria-hidden="true"></span>簽章驗證</li>
                    <li><span class="chip-icon chip-help" aria-hidden="true"></span><a href="{{ route('shop.support.index') }}">客服支援</a></li>
                </ul>
                <p class="muted">固定匯率 1 元 = {{ config('topup.points_per_twd') }} 點。所有付款都是模擬的，不會產生真實交易。</p>
            </div>
        </section>

        {{-- 方案 --}}
        <section class="card">
            <h2 class="section-title">選擇方案</h2>
            @error('package_id')<p class="field-error">{{ $message }}</p>@enderror
            <div class="package-grid" role="radiogroup" aria-label="儲值方案">
                @forelse ($packages as $package)
                    <label class="package-card">
                        <input type="radio" name="package_id" value="{{ $package->id }}" @checked($selected === $package->id)
                               data-name="{{ $package->name }}" data-price="{{ $package->price }}" data-points="{{ $package->points() }}">
                        <span class="package-art" aria-hidden="true"><span class="coin"></span><span class="coin coin-2"></span></span>
                        <span class="package-points">{{ number_format($package->points()) }}<small> 點</small></span>
                        <span class="package-name">{{ $package->name }}</span>
                        <span class="package-price">NT${{ number_format($package->price) }}</span>
                        <span class="package-check" aria-hidden="true"></span>
                    </label>
                @empty
                    <p class="muted">目前沒有上架的方案</p>
                @endforelse
            </div>
        </section>

        {{-- 付款方式 --}}
        <section class="card">
            <h2 class="section-title">支付方式</h2>
            <div class="method-grid" role="radiogroup" aria-label="付款方式">
                @foreach ($methods as $value => [$label, $hint])
                    <label class="method-card">
                        <input type="radio" name="method" value="{{ $value }}" @checked($selectedMethod === $value) data-label="{{ $label }}">
                        <span class="method-icon ic-{{ $value }}" aria-hidden="true"></span>
                        <span class="method-text"><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                        @if ($value === 'card')
                            <span class="method-logos" aria-hidden="true">
                                @foreach (['visa', 'mastercard', 'jcb', 'amex'] as $brand)<img src="/img/payments/{{ $brand }}.svg" alt="" width="20" height="20">@endforeach
                            </span>
                        @endif
                    </label>
                @endforeach
            </div>
            <p class="muted">下一步會進到模擬付款頁輸入付款資料；在付款頁也可以改用其他方式。</p>
        </section>
    </div>

    {{-- 結帳欄 --}}
    <aside class="topup-side">
        <section class="card checkout-card">
            <h2 class="section-title">儲值帳戶</h2>
            <dl class="checkout-rows">
                <dt>遊戲帳號</dt><dd>玩家 #{{ $player->game_player_id }} <span class="badge ok">已登入</span></dd>
            </dl>
        </section>
        <section class="card checkout-card checkout-sticky">
            <dl class="checkout-rows">
                <dt>方案</dt><dd id="sum-name">—</dd>
                <dt>可得點數</dt><dd id="sum-points">—</dd>
                <dt>付款方式</dt><dd id="sum-method">—</dd>
            </dl>
            <div class="checkout-total"><span>總價</span><strong id="sum-price">—</strong></div>
            <button type="submit" class="checkout-button" id="topup-submit" @disabled($packages->isEmpty())>立即儲值</button>
            <p class="checkout-safe"><span class="chip-icon chip-safe" aria-hidden="true"></span>模擬金流，不會產生真實交易</p>
        </section>
        <section class="card checkout-card">
            <h2 class="section-title small">安全結帳保障</h2>
            <p class="muted">可信付款方式</p>
            @include('shop.partials.pay-logos')
        </section>
    </aside>
</form>
<script src="/shop.js" defer></script>
@endsection
