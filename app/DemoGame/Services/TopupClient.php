<?php

namespace App\DemoGame\Services;

use App\Support\ApiSignature;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** 玩家端呼叫充值端的簽章 API。 */
class TopupClient
{
    public function submitRefund(array $data): Response
    {
        return $this->send('/api/topup/refund-requests', $data);
    }

    /** @throws \Illuminate\Http\Client\RequestException */
    public function revokeSessions(int $playerId): void
    {
        $this->send("/api/topup/players/$playerId/revoke-sessions", [])->throw();
    }

    public function refundRequests(int $playerId): array
    {
        return $this->send("/api/topup/players/$playerId/refund-requests", [])->throw()->json('data') ?? [];
    }

    public function bankAccounts(int $playerId): array
    {
        return $this->send("/api/topup/players/$playerId/bank-accounts", [])->throw()->json('data') ?? [];
    }

    public function addBankAccount(int $playerId, array $data): Response
    {
        return $this->send("/api/topup/players/$playerId/bank-accounts/add", $data);
    }

    /** @throws \Illuminate\Http\Client\RequestException */
    public function deleteBankAccount(int $playerId, int $accountId): void
    {
        $this->send("/api/topup/players/$playerId/bank-accounts/$accountId/delete", [])->throw();
    }

    public function orders(int $playerId): array
    {
        return $this->send("/api/topup/players/$playerId/orders", [])->throw()->json('data') ?? [];
    }

    public function packages(): array
    {
        return $this->send('/api/topup/packages', [])->throw()->json('data') ?? [];
    }

    public function createGameOrder(int $playerId, string $requestId, int $packageId): Response
    {
        return $this->send("/api/topup/players/$playerId/game-orders", ['request_id' => $requestId, 'package_id' => $packageId]);
    }

    public function gameOrder(int $playerId, string $orderNo): Response
    {
        return $this->send("/api/topup/players/$playerId/game-orders/$orderNo", []);
    }

    private function send(string $path, array $data): Response
    {
        $body = json_encode($data);

        return Http::withHeaders(ApiSignature::headers((string) config('topup.topup_api_secret'), 'POST', $path, $body))
            ->withBody($body, 'application/json')
            ->timeout(10)
            ->post(rtrim(config('topup.internal_api_url'), '/').$path);
    }
}
