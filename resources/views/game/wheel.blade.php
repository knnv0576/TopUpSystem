@extends('game.layout')
@section('title', '轉盤小遊戲')
@section('content')
<h1>轉盤小遊戲</h1>
<div id="wheel-app"
     data-csrf="{{ csrf_token() }}"
     data-public-id="{{ $round->public_id }}"
     data-spin-url="{{ url('/game/wheel') }}"
     data-topup-url="{{ route('game.wheel.topups') }}">

    <div class="grid">
        <div class="card stat"><div class="label">可用點數</div><div class="value" id="wheel-available">{{ number_format($wallet->available()) }}</div></div>
        <div class="card">
            <h2>遊戲內充值</h2>
            <p class="muted">模擬付款，不會收取真實款項。1 元 = 100 點。</p>
            @forelse ($packages as $package)
                <button type="button" class="secondary" data-topup="{{ $package['id'] }}" data-price="{{ $package['price'] }}" data-points="{{ $package['points'] }}">{{ number_format($package['price']) }} 元（{{ number_format($package['points']) }} 點）</button>
            @empty
                <p class="muted">目前無法取得充值方案，請稍後再試。</p>
            @endforelse
            <p id="topup-message" role="status"></p>
        </div>
    </div>

    <div class="card wheel-card">
        <div class="wheel-stage">
            <div class="wheel-pointer" aria-hidden="true"></div>
            <svg id="wheel" class="wheel" viewBox="-104 -104 208 208" role="img" aria-label="轉盤，扇形大小等於機率">
                <g id="wheel-rotor">
                    @foreach ($sectors as $sector)
                        <path class="sector sector-{{ $sector['key'] }}" d="{{ $sector['path'] }}"></path>
                    @endforeach
                    @foreach ($sectors as $sector)
                        <text class="sector-label" x="{{ $sector['label_x'] }}" y="{{ $sector['label_y'] }}" transform="rotate({{ $sector['label_rotate'] }} {{ $sector['label_x'] }} {{ $sector['label_y'] }})">{{ $sector['label'] }}</text>
                    @endforeach
                </g>
            </svg>
        </div>

        <div class="wheel-controls">
            @unless ($enabled)
                <p class="warning">轉盤目前暫停服務。</p>
            @endunless

            <fieldset class="bets">
                <legend>下注點數</legend>
                @foreach ($bets as $bet)
                    <label><input type="radio" name="bet" value="{{ $bet }}" @checked($loop->first)> {{ number_format($bet) }} 點</label>
                @endforeach
            </fieldset>

            <label class="check"><input type="checkbox" id="wheel-confirm-toggle" checked> 轉動前先確認</label>

            <div class="actions">
                <button type="button" id="wheel-spin" @disabled(! $enabled)>轉動</button>
                <a class="button secondary" href="{{ route('game.wheel.rounds') }}">遊戲紀錄</a>
            </div>

            <p id="wheel-message" class="wheel-message" role="status"></p>

            <details class="fairness">
                <summary>公平性資訊</summary>
                <p class="muted">開局前先公布伺服器種子的雜湊，結算後公開種子，你可以在遊戲紀錄頁自行驗算。</p>
                <p class="muted">本局伺服器種子雜湊</p>
                <span id="wheel-hash" class="code">{{ $round->server_seed_hash }}</span>
                <label for="wheel-client-seed">玩家種子（可修改）</label>
                <input id="wheel-client-seed" type="text" maxlength="64" autocomplete="off">
            </details>
        </div>
    </div>

    <div class="card">
        <h2>機率與倍率</h2>
        <p class="muted">長期平均回報率 {{ config('minigame.wheel.target_rtp_pct') }}%，也就是長期來看會輸點數。</p>
        <div class="table-wrap">
        <table>
            <thead><tr><th>倍率</th><th class="num">機率</th></tr></thead>
            <tbody>
            @foreach ($sectors as $sector)
                <tr><td><span class="swatch sector-{{ $sector['key'] }}"></span>{{ $sector['label'] }}</td><td class="num">{{ $sector['weight'] }}%</td></tr>
            @endforeach
            </tbody>
        </table>
        </div>
    </div>

    {{-- 遊戲內充值：選付款方式 → 輸入付款資料 → 等待結果。付款資料由伺服器轉送給充值端驗證，不會保存。 --}}
    <dialog id="topup-dialog" class="topup-dialog" aria-labelledby="topup-dialog-title">
        <h2 id="topup-dialog-title">遊戲內充值</h2>
        <p class="warning">這是模擬金流，不會產生真實交易。請勿輸入真實的信用卡或銀行資料，信用卡只接受下方列出的測試卡號。</p>
        <dl class="facts">
            <dt>應付金額</dt><dd id="topup-amount"></dd>
            <dt>可得點數</dt><dd id="topup-points"></dd>
            <dt class="topup-order-row" hidden>訂單編號</dt><dd class="topup-order-row" hidden><span class="code" id="topup-order-no"></span></dd>
            <dt class="topup-order-row" hidden>付款期限</dt><dd class="topup-order-row" hidden id="topup-expires"></dd>
        </dl>

        {{-- 步驟 1：選擇付款方式 --}}
        <section data-topup-step="choose">
            <h3>選擇付款方式</h3>
            <div class="method-grid" role="radiogroup" aria-label="付款方式">
                @foreach (['card' => ['信用卡', '國際信用卡、金融卡'], 'atm' => ['ATM 虛擬帳號', '取得專屬帳號後轉帳'], 'cvs' => ['超商代碼', '到超商繳費']] as $value => [$label, $hint])
                    <label class="method-card">
                        <input type="radio" name="topup-method" value="{{ $value }}" @checked($loop->first)>
                        <span class="method-icon ic-{{ $value }}" aria-hidden="true"></span>
                        <span class="method-text"><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                    </label>
                @endforeach
            </div>
            <p class="field-error" id="topup-choose-error" role="alert"></p>
            <div class="actions">
                <button type="button" id="topup-create">下一步：輸入付款資料</button>
                <button type="button" class="secondary" data-topup-close>取消</button>
            </div>
        </section>

        {{-- 步驟 2：輸入付款資料（只顯示選好的付款方式） --}}
        <section data-topup-step="pay" hidden>
            <p class="field-error" id="topup-pay-error" role="alert"></p>

            <section data-pay-panel="card" aria-label="信用卡付款">
                <form class="pay-form" data-topup-pay novalidate>
                    <label for="card_name">持卡人姓名（英文，與卡片相同）</label>
                    <input type="text" id="card_name" name="card_name" maxlength="50" autocomplete="off" spellcheck="false" placeholder="WANG XIAO MING" data-check="name">
                    <p class="field-error" data-error-for="card_name"></p>

                    <label for="card_number">卡號 <span class="card-brand" id="card-brand"></span></label>
                    <input type="text" id="card_number" name="card_number" inputmode="numeric" maxlength="23" autocomplete="off" placeholder="0000 0000 0000 0000" data-check="number">
                    <p class="field-error" data-error-for="card_number"></p>

                    <div class="field-row">
                        <div>
                            <label for="card_expiry">有效期限（MM/YY）</label>
                            <input type="text" id="card_expiry" name="card_expiry" inputmode="numeric" maxlength="5" autocomplete="off" placeholder="MM/YY" data-check="expiry">
                            <p class="field-error" data-error-for="card_expiry"></p>
                        </div>
                        <div>
                            <label for="card_cvv">安全碼（卡片背面）</label>
                            <input type="password" id="card_cvv" name="card_cvv" inputmode="numeric" maxlength="4" autocomplete="off" placeholder="3 碼" data-check="cvv">
                            <p class="field-error" data-error-for="card_cvv"></p>
                        </div>
                    </div>
                    <div class="actions"><button type="submit" data-pay-label>確認付款</button></div>
                </form>
                <h3 class="test-cards-title">測試卡號</h3>
                <p class="muted">有效期限填未來的月份，安全碼任意。付款結果由卡號決定。</p>
                <div class="table-wrap">
                    <table class="test-cards">
                        <thead><tr><th>卡號</th><th>卡別</th><th>結果</th><th></th></tr></thead>
                        <tbody id="topup-test-cards"></tbody>
                    </table>
                </div>
            </section>

            <section data-pay-panel="atm" aria-label="ATM 虛擬帳號付款">
                <p>請在期限內以 ATM 或網路銀行轉帳到下面的虛擬帳號。虛擬帳號只對應這筆訂單，金額不符會轉帳失敗。</p>
                <dl class="facts">
                    <dt>轉入銀行代碼</dt><dd><span class="code" id="topup-atm-bank"></span>（模擬銀行）</dd>
                    <dt>虛擬帳號</dt><dd><span class="code" id="topup-atm-account"></span></dd>
                </dl>
                <form class="pay-form" data-topup-pay novalidate>
                    <label for="atm_bank">轉入銀行代碼</label>
                    <input type="text" id="atm_bank" name="atm_bank" inputmode="numeric" maxlength="3" autocomplete="off" data-check="digits" data-length="3" data-label="轉入銀行代碼">
                    <p class="field-error" data-error-for="atm_bank"></p>
                    <label for="atm_account">轉入帳號</label>
                    <input type="text" id="atm_account" name="atm_account" inputmode="numeric" maxlength="18" autocomplete="off" data-check="digits" data-length="14" data-label="轉入帳號">
                    <p class="field-error" data-error-for="atm_account"></p>
                    <label for="atm_amount">轉帳金額（元）</label>
                    <input type="text" id="atm_amount" name="atm_amount" inputmode="numeric" maxlength="9" autocomplete="off" data-check="amount" data-label="轉帳金額">
                    <p class="field-error" data-error-for="atm_amount"></p>
                    <div class="actions"><button type="submit">模擬轉帳</button></div>
                </form>
            </section>

            <section data-pay-panel="cvs" aria-label="超商代碼付款">
                <p>請在期限內到超商的多媒體機輸入繳費代碼，列印繳費單後到櫃檯付款。</p>
                <dl class="facts">
                    <dt>繳費代碼</dt><dd><span class="code" id="topup-cvs-code"></span></dd>
                </dl>
                <form class="pay-form" data-topup-pay novalidate>
                    <label for="cvs_code">繳費代碼</label>
                    <input type="text" id="cvs_code" name="cvs_code" maxlength="14" autocomplete="off" spellcheck="false" data-check="code" data-label="繳費代碼">
                    <p class="field-error" data-error-for="cvs_code"></p>
                    <label for="cvs_amount">繳費金額（元）</label>
                    <input type="text" id="cvs_amount" name="cvs_amount" inputmode="numeric" maxlength="9" autocomplete="off" data-check="amount" data-label="繳費金額">
                    <p class="field-error" data-error-for="cvs_amount"></p>
                    <div class="actions"><button type="submit">模擬繳費</button></div>
                </form>
            </section>

            <div class="actions">
                <button type="button" class="secondary" data-topup-close>稍後再付</button>
            </div>
            <p class="muted">關閉後這筆訂單不會付款，逾期自動失效，需要時重新充值即可。</p>
        </section>

        {{-- 步驟 3：結果 --}}
        <section data-topup-step="result" hidden>
            <p id="topup-result" class="wheel-message" role="status"></p>
            <div class="actions"><button type="button" data-topup-close>關閉</button></div>
        </section>
    </dialog>

    <dialog id="wheel-confirm-dialog">
        <p>確定下注 <strong id="wheel-confirm-bet"></strong> 點？按下確定後會立即扣點。</p>
        <div class="actions">
            <button type="button" id="wheel-confirm-ok">確定</button>
            <button type="button" class="secondary" id="wheel-confirm-cancel">取消</button>
        </div>
    </dialog>
</div>
{{-- mock-pay.js 先載入：它的格式檢查先擋下錯誤，wheel.js 才送出。 --}}
<script src="/mock-pay.js" defer></script>
<script src="/wheel.js" defer></script>
@endsection
