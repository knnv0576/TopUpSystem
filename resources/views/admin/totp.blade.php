@extends('admin.layout')
@section('title', '兩步驟驗證')
@section('content')
<h1>兩步驟驗證</h1>
<div class="card">
    @if ($setup)
        <p>第一次登入：請用驗證器 App（例如 Google Authenticator、Microsoft Authenticator）掃描下面的 QR Code，再輸入 App 產生的 6 碼驗證碼。</p>
        <div class="totp-setup">
            <figure class="totp-qr" aria-label="TOTP 綁定 QR Code">{!! $qr !!}</figure>
            <div>
                <p><strong>無法掃描時</strong>，在 App 選「輸入設定金鑰」，類型選「依時間」：</p>
                <p>帳戶：<span class="code">{{ $username }}</span></p>
                <p>金鑰：<span class="code totp-secret">{{ trim(chunk_split($secret, 4, ' ')) }}</span></p>
                <p class="muted">QR Code 與金鑰只在這台電腦上產生，請勿截圖或分享給他人。綁定完成後這個頁面就不會再顯示。</p>
            </div>
        </div>
    @endif
    <form method="post" action="{{ route('admin.totp') }}">
        @csrf
        <label for="code">6 碼驗證碼</label>
        <input id="code" type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
        <div class="actions"><button type="submit">驗證</button></div>
    </form>
</div>
@endsection
