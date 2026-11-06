<!DOCTYPE html>
<html lang="zh-Hant-TW">
<body style="font-family:'Microsoft JhengHei',sans-serif;color:#1f2933;line-height:1.6">
<p style="color:#b54708;font-size:13px">本機 DEMO 信件，只會出現在 Mailpit。</p>
<p>你好，客服已回覆你的問題「{{ $ticket->subject }}」（工單 {{ $ticket->ticket_no }}）：</p>
<div style="border-left:3px solid #d9e2ec;padding:4px 12px;margin:12px 0;white-space:pre-wrap">{{ $supportMessage->body }}</div>
@if ($ticket->isClosed())
    <p>這張工單已結案。如果還有問題，請登入後另外提出。</p>
@else
    <p>如需補充說明，請登入玩家中心，在「客服」頁面回覆。</p>
@endif
<p><a href="{{ route('game.support') }}">前往客服頁面</a></p>
<p style="color:#52606d;font-size:13px">這是系統自動寄出的信件，請不要直接回信。</p>
</body>
</html>
