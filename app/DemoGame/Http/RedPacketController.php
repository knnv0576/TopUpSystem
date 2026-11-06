<?php

namespace App\DemoGame\Http;

use App\DemoGame\Exceptions\InsufficientPoints;
use App\DemoGame\Models\GamePlayer;
use App\DemoGame\Models\RedPacket;
use App\DemoGame\Services\RedPacketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

class RedPacketController
{
    public function index(): View
    {
        $player = Auth::guard('game')->user();

        return view('game.red-packets', [
            'wallet' => $player->wallet,
            'inbox' => RedPacket::with('sender')->where('recipient_id', $player->id)->orderByDesc('id')->limit(50)->get(),
            'sent' => RedPacket::with('recipient')->where('sender_id', $player->id)->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    /** 送出前的確認畫面：同時顯示對方的編號與暱稱，避免發錯人。 */
    public function confirm(Request $request): View|RedirectResponse
    {
        $data = $this->validated($request);
        $player = Auth::guard('game')->user();
        $recipient = GamePlayer::where('player_no', $data['player_no'])->whereNull('suspended_at')->first();

        if (! $recipient) {
            return back()->withErrors(['player_no' => '找不到這位玩家'])->withInput();
        }
        if ($recipient->id === $player->id) {
            return back()->withErrors(['player_no' => '不能發紅包給自己'])->withInput();
        }
        if ($player->wallet->available() < $data['points']) {
            return back()->withErrors(['points' => '可用點數不足'])->withInput();
        }

        return view('game.red-packet-confirm', ['recipient' => $recipient, 'data' => $data]);
    }

    public function send(Request $request, RedPacketService $packets): RedirectResponse
    {
        $data = $this->validated($request) + $request->validate(['request_id' => 'required|uuid']);

        try {
            $packets->send(Auth::guard('game')->user(), $data['player_no'], (int) $data['points'], $data['message'] ?? null, $data['request_id']);
        } catch (InsufficientPoints|RuntimeException $e) {
            return redirect()->route('game.red-packets')->withErrors(['points' => $e->getMessage()]);
        }

        return redirect()->route('game.red-packets')->with('status', '紅包已送出');
    }

    public function claim(int $packet, RedPacketService $packets): RedirectResponse
    {
        try {
            $claimed = $packets->claim(Auth::guard('game')->user(), $packet);
        } catch (RuntimeException $e) {
            return redirect()->route('game.red-packets')->withErrors(['points' => $e->getMessage()]);
        }

        return redirect()->route('game.red-packets')->with('status', '已領取 '.number_format($claimed->points).' 點');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'player_no' => 'required|string|max:12',
            'points' => 'required|integer|min:1',
            'message' => 'nullable|string|max:100',
        ]);
    }
}
