<?php

namespace App\MiniGame\Services;

use App\MiniGame\Models\WheelRound;
use App\Support\ApiSignature;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** 小遊戲呼叫點數 API。金額一律來自保存的局資料，每次呼叫都重新簽章。 */
class PointsClient
{
    public function debit(WheelRound $round): Response
    {
        return $this->send('/api/points/debit', [
            'player_no' => $round->player_no,
            'points' => $round->bet_points,
            'idempotency_key' => $round->debitKey(),
            'purpose' => '轉盤下注',
        ]);
    }

    public function voidDebit(WheelRound $round): Response
    {
        return $this->send('/api/points/debit/void', [
            'player_no' => $round->player_no,
            'points' => $round->bet_points,
            'idempotency_key' => $round->debitKey(),
        ]);
    }

    public function credit(WheelRound $round): Response
    {
        return $this->send('/api/points/credit', [
            'player_no' => $round->player_no,
            'points' => $round->payout_points,
            'debit_idempotency_key' => $round->debitKey(),
            'purpose' => '轉盤獎勵',
        ]);
    }

    private function send(string $path, array $data): Response
    {
        $body = json_encode($data);

        return Http::withHeaders(ApiSignature::headers((string) config('topup.game_api_secret'), 'POST', $path, $body))
            ->withBody($body, 'application/json')
            ->timeout(10)
            ->post(rtrim(config('topup.internal_api_url'), '/').$path);
    }
}
