<?php

namespace App\DemoGame\Services;

use App\DemoGame\Models\GamePlayer;
use App\DemoGame\Models\RedPacket;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 一對一定額紅包。狀態一律用條件更新轉換，領取、過期、停權退回同時發生時只有一個會生效。
 */
class RedPacketService
{
    public const TTL_HOURS = 24;

    public function __construct(private WalletService $wallets) {}

    public function send(GamePlayer $sender, string $recipientNo, int $points, ?string $message, string $requestId): RedPacket
    {
        if ($points < 1) {
            throw new RuntimeException('紅包至少要 1 點');
        }

        $recipient = GamePlayer::where('player_no', $recipientNo)->first();
        if (! $recipient || $recipient->isSuspended()) {
            throw new RuntimeException('找不到這位玩家');
        }
        if ($recipient->id === $sender->id) {
            throw new RuntimeException('不能發紅包給自己');
        }

        return DB::transaction(function () use ($sender, $recipient, $points, $message, $requestId) {
            // debit 會鎖定發送者錢包，重複送出同一張表單時會排隊，帳本與紅包都只產生一次。
            $this->wallets->debit($sender->id, $points, 'red_packet_send', "red-packet:$requestId:send", '發送紅包給 '.$recipient->player_no);

            return RedPacket::firstOrCreate(['request_id' => $requestId], [
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
                'points' => $points,
                'message' => $message,
                'status' => 'sent',
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);
        });
    }

    public function claim(GamePlayer $recipient, int $packetId): RedPacket
    {
        return DB::transaction(function () use ($recipient, $packetId) {
            $claimed = RedPacket::whereKey($packetId)
                ->where('recipient_id', $recipient->id)
                ->where('status', 'sent')
                ->where('expires_at', '>', now())
                ->update(['status' => 'claimed', 'closed_at' => now(), 'updated_at' => now()]);

            if ($claimed !== 1) {
                throw new RuntimeException('這個紅包無法領取');
            }

            $packet = RedPacket::findOrFail($packetId);
            $this->wallets->credit($recipient->id, $packet->points, 'red_packet_claim', "red-packet:{$packet->id}:claim", '領取紅包');

            return $packet;
        });
    }

    /** 退回所有到期紅包，回傳處理筆數。 */
    public function expireDue(): int
    {
        $count = 0;
        RedPacket::where('status', 'sent')->where('expires_at', '<=', now())->orderBy('id')
            ->each(function (RedPacket $packet) use (&$count) {
                $count += $this->close($packet, 'expired', '紅包逾時退回') ? 1 : 0;
            });

        return $count;
    }

    /** 停權：退回與該玩家有關的所有未領紅包，不論他是發送者或收件者。 */
    public function returnAllFor(int $playerId): int
    {
        $count = 0;
        RedPacket::where('status', 'sent')
            ->where(fn ($q) => $q->where('sender_id', $playerId)->orWhere('recipient_id', $playerId))
            ->orderBy('id')
            ->each(function (RedPacket $packet) use (&$count) {
                $count += $this->close($packet, 'returned', '停權退回紅包') ? 1 : 0;
            });

        return $count;
    }

    private function close(RedPacket $packet, string $status, string $memo): bool
    {
        return DB::transaction(function () use ($packet, $status, $memo) {
            $closed = RedPacket::whereKey($packet->id)->where('status', 'sent')
                ->update(['status' => $status, 'closed_at' => now(), 'updated_at' => now()]);

            if ($closed !== 1) {
                return false;
            }
            $this->wallets->credit($packet->sender_id, $packet->points, 'red_packet_return', "red-packet:{$packet->id}:return", $memo);

            return true;
        });
    }
}
