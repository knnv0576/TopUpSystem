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
                <button type="button" class="secondary" data-topup="{{ $package['id'] }}">{{ number_format($package['price']) }} 元（{{ number_format($package['points']) }} 點）</button>
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

    <dialog id="wheel-confirm-dialog">
        <p>確定下注 <strong id="wheel-confirm-bet"></strong> 點？按下確定後會立即扣點。</p>
        <div class="actions">
            <button type="button" id="wheel-confirm-ok">確定</button>
            <button type="button" class="secondary" id="wheel-confirm-cancel">取消</button>
        </div>
    </dialog>
</div>
<script src="/wheel.js" defer></script>
@endsection
