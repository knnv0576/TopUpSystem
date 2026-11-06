<!DOCTYPE html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '點數充值 DEMO')</title>
    @yield('head')
    <link rel="stylesheet" href="/app.css">
    @yield('styles')
    <script src="/app.js" defer></script>
</head>
<body class="@yield('body_class')">
<div class="demo-banner">DEMO 環境：所有金流皆為模擬，請勿輸入真實卡號、帳號或個人資料。</div>
<header class="top">
    <div class="inner">
        <strong>@yield('brand', '點數充值 DEMO')</strong>
        <nav>@yield('nav')</nav>
        <div class="right">@yield('account')</div>
    </div>
</header>
<main>
    @if (session('status'))
        <div class="flash ok" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash err" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @yield('content')
</main>
@hasSection('footer')
<footer class="site-footer">@yield('footer')</footer>
@endif
</body>
</html>
