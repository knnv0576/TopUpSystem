<!DOCTYPE html>
<html lang="zh-Hant-TW">
<body style="font-family:'Microsoft JhengHei',sans-serif;color:#1f2933;line-height:1.6">
<p style="color:#b54708;font-size:13px">本機 DEMO 信件，只會出現在 Mailpit。</p>
<h2 style="font-size:18px">{{ $kind }}：{{ $ticket->subject }}</h2>
<table style="font-size:14px">
    <tr><td style="color:#52606d;padding-right:12px">工單編號</td><td>{{ $ticket->ticket_no }}</td></tr>
    <tr><td style="color:#52606d;padding-right:12px">類別</td><td>{{ $ticket->categoryLabel() }}</td></tr>
    <tr><td style="color:#52606d;padding-right:12px">玩家</td><td>#{{ $ticket->player->game_player_id }}</td></tr>
    @if ($ticket->order_no)
        <tr><td style="color:#52606d;padding-right:12px">訂單編號</td><td>{{ $ticket->order_no }}</td></tr>
    @endif
</table>
<div style="border-left:3px solid #d9e2ec;padding:4px 12px;margin:12px 0;white-space:pre-wrap">{{ $supportMessage->body }}</div>
<p><a href="{{ route('admin.support.show', $ticket) }}">到後台回覆</a></p>
</body>
</html>
