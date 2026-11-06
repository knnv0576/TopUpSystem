@extends('admin.layout')
@section('title', '玩家')
@section('content')
<h1>玩家</h1>
<div class="card">
    <form method="get" action="{{ route('admin.players') }}" class="actions">
        <div>
            <label for="player_no">以玩家編號查詢</label>
            <input id="player_no" type="text" name="player_no" maxlength="12" required>
        </div>
        <button class="secondary" type="submit">查詢</button>
    </form>
</div>
<div class="card">
    <h2>充值端已有紀錄的玩家</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>內部編號</th><th>遊戲玩家 ID</th><th>狀態</th></tr></thead>
        <tbody>
        @forelse ($players as $player)
            <tr>
                <td><a href="{{ route('admin.players.show', $player) }}">#{{ $player->id }}</a></td>
                <td>{{ $player->game_player_id }}</td>
                <td>@if ($player->isSuspended()) <span class="badge err">已停權</span> @else 正常 @endif</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">沒有資料</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    <div class="pagination">{{ $players->links('pagination::simple-tailwind') }}</div>
</div>
@endsection
