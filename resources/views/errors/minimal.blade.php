@php
    $messages = [
        '401' => '請先登入。',
        '403' => '你沒有權限使用這個頁面。',
        '404' => '找不到這個頁面。',
        '419' => '頁面已過期，請回上一頁重新整理後再試一次。',
        '429' => '操作太頻繁，請稍候一分鐘再試。',
        '500' => '系統發生錯誤，請稍後再試。',
        '503' => '系統暫時無法使用，請稍後再試。',
    ];
    $code = trim((string) $__env->yieldContent('code'));
@endphp
<!DOCTYPE html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $code }} 錯誤</title>
    <link rel="stylesheet" href="/app.css">
</head>
<body>
<div class="demo-banner">DEMO 環境：所有金流皆為模擬，請勿輸入真實卡號、帳號或個人資料。</div>
<main>
    <h1>錯誤 {{ $code }}</h1>
    <div class="card">
        <p>{{ $messages[$code] ?? '無法完成這個請求。' }}</p>
        <p><a href="/">回首頁</a></p>
    </div>
</main>
</body>
</html>
