<?php

namespace App\Game;

use App\Support\ApiSignature;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** 透過簽章 HTTP API 呼叫玩家端。 */
class HttpGameGateway implements GameGateway
{
    public function grantPoints(int $gamePlayerId, int $points, string $key, string $memo): void
    {
        $this->post("players/$gamePlayerId/grant", compact('points', 'key', 'memo'));
    }

    public function freezePoints(int $gamePlayerId, int $points, string $ref): void
    {
        $this->post("players/$gamePlayerId/freeze", compact('points', 'ref'));
    }

    public function unfreezePoints(int $gamePlayerId, int $points, string $ref): void
    {
        $this->post("players/$gamePlayerId/unfreeze", compact('points', 'ref'));
    }

    public function deductFrozenPoints(int $gamePlayerId, int $points, string $ref): void
    {
        $this->post("players/$gamePlayerId/deduct-frozen", compact('points', 'ref'));
    }

    public function returnDeductedPoints(int $gamePlayerId, int $points, string $ref): void
    {
        $this->post("players/$gamePlayerId/return-deducted", compact('points', 'ref'));
    }

    public function adjustPoints(int $gamePlayerId, string $direction, int $points, string $key): void
    {
        $this->post("players/$gamePlayerId/adjust", compact('direction', 'points', 'key'));
    }

    public function suspendPlayer(int $gamePlayerId): void
    {
        $this->post("players/$gamePlayerId/suspend", []);
    }

    public function unsuspendPlayer(int $gamePlayerId): void
    {
        $this->post("players/$gamePlayerId/unsuspend", []);
    }

    public function findPlayer(string $playerNo): ?array
    {
        return $this->lookup(['player_no' => $playerNo]);
    }

    public function getPlayer(int $gamePlayerId): ?array
    {
        return $this->lookup(['id' => $gamePlayerId]);
    }

    private function lookup(array $criteria): ?array
    {
        $response = $this->send($this->path('players/lookup'), $criteria);

        return $response->status() === 404 ? null : $response->throw()->json();
    }

    private function post(string $uri, array $data): void
    {
        $response = $this->send($this->path($uri), $data);

        if ($response->status() === 422 && $response->json('error') === 'insufficient_points') {
            throw new InsufficientGamePoints;
        }

        $response->throw();
    }

    private function send(string $path, array $data): Response
    {
        $body = json_encode($data);

        return Http::withHeaders(ApiSignature::headers((string) config('topup.game_api_secret'), 'POST', $path, $body))
            ->withBody($body, 'application/json')
            ->timeout(10)
            ->post(rtrim(config('topup.internal_api_url'), '/').$path);
    }

    private function path(string $uri): string
    {
        return "/api/game/$uri";
    }
}
