<?php

namespace App\Http\Controllers\Admin;

use App\Game\GameGateway;
use App\Models\Player;
use App\Models\PointAdjustment;
use App\Services\PlayerSuspensionService;
use App\Services\PointAdjustmentService;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

class PlayerController
{
    public function __construct(
        private GameGateway $game,
        private PointAdjustmentService $adjustments,
        private PlayerSuspensionService $suspensions,
        private TotpService $totp,
    ) {}

    /** 以玩家編號查詢；帳號由玩家端管理，查到時才在充值端建立對應紀錄。 */
    public function index(Request $request): View|RedirectResponse
    {
        $no = trim((string) $request->query('player_no'));
        if ($no !== '') {
            $info = $this->game->findPlayer($no);
            if (! $info) {
                return back()->withErrors(['player_no' => '找不到這位玩家']);
            }

            return redirect()->route('admin.players.show', Player::forGame($info['id']));
        }

        return view('admin.players', ['players' => Player::orderByDesc('id')->paginate(30)]);
    }

    public function show(Player $player): View
    {
        return view('admin.player', [
            'player' => $player,
            'info' => $this->game->getPlayer($player->game_player_id),
            'adjustments' => PointAdjustment::with(['initiator', 'supervisor'])->where('player_id', $player->id)->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function adjustments(): View
    {
        return view('admin.adjustments', [
            'adjustments' => PointAdjustment::with(['player', 'initiator', 'supervisor'])->orderByDesc('id')->paginate(30),
        ]);
    }

    public function initiateAdjustment(Request $request, Player $player): RedirectResponse
    {
        $data = $request->validate([
            'direction' => 'required|in:add,deduct',
            'points' => 'required|integer|min:1',
            'reason' => 'required|string|max:200',
        ]);

        return $this->attempt(fn () => $this->adjustments->initiate($player, $this->admin(), $data['direction'], (int) $data['points'], $data['reason']), '已發起，等待覆核');
    }

    public function approveAdjustment(Request $request, PointAdjustment $adjustment): RedirectResponse
    {
        if (! $this->totp->verify($this->admin(), (string) $request->input('code'))) {
            return back()->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        try {
            $applied = $this->adjustments->approve($adjustment, $this->admin());
        } catch (RuntimeException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return $applied ? back()->with('status', '調整已生效') : back()->withErrors(['action' => '玩家可用點數不足，調整已拒絕']);
    }

    public function rejectAdjustment(Request $request, PointAdjustment $adjustment): RedirectResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:200'])['reason'];

        return $this->attempt(fn () => $this->adjustments->reject($adjustment, $this->admin(), $reason), '調整已拒絕');
    }

    public function suspend(Request $request, Player $player): RedirectResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:200'])['reason'];
        if (! $this->totp->verify($this->admin(), (string) $request->input('code'))) {
            return back()->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        return $this->attempt(fn () => $this->suspensions->suspend($player, $this->admin(), $reason), '玩家已停權');
    }

    public function unsuspend(Request $request, Player $player): RedirectResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:200'])['reason'];
        if (! $this->totp->verify($this->admin(), (string) $request->input('code'))) {
            return back()->withErrors(['code' => '驗證碼錯誤或已使用，請等待下一組']);
        }

        return $this->attempt(fn () => $this->suspensions->unsuspend($player, $this->admin(), $reason), '已解除停權');
    }

    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }

    private function admin()
    {
        return Auth::guard('admin')->user();
    }
}
