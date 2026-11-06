<?php

namespace App\Services;

use App\Mail\SupportReplyToPlayer;
use App\Mail\SupportTicketNotice;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Player;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Payments\MockCheckout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 客服工單。訊息只新增不修改；通知信在交易提交後才送進佇列，
 * 寄信失敗不影響工單本身（信件只寄到本機 Mailpit）。
 */
class SupportService
{
    public function create(Player $player, array $data): SupportTicket
    {
        $this->assertNoCardNumber($data['body'], 'body');
        $orderNo = $data['order_no'] ?? null;
        if ($orderNo !== null && ! Order::where('order_no', $orderNo)->where('player_id', $player->id)->exists()) {
            throw ValidationException::withMessages(['order_no' => '找不到這筆訂單，請確認訂單編號']);
        }

        return DB::transaction(function () use ($player, $data, $orderNo) {
            $ticket = SupportTicket::create([
                'ticket_no' => 'S'.now()->format('ymdHis').Str::upper(Str::random(6)),
                'player_id' => $player->id,
                'category' => $data['category'],
                'subject' => $data['subject'],
                'order_no' => $orderNo,
                'contact_email' => $data['contact_email'] ?? null,
                'status' => 'open',
                'last_message_at' => now(),
            ]);
            $message = $ticket->messages()->create(['author_type' => 'player', 'body' => $data['body'], 'created_at' => now()]);

            AuditLog::record('support.created', $ticket, ['to' => 'open', 'actor_type' => 'player', 'actor' => (string) $player->game_player_id, 'note' => $ticket->categoryLabel()]);
            $this->notifySupport($ticket, $message, '新工單');

            return $ticket;
        });
    }

    public function playerReply(SupportTicket $ticket, string $body): SupportMessage
    {
        $this->assertNoCardNumber($body, 'body');

        return DB::transaction(function () use ($ticket, $body) {
            $ticket = SupportTicket::whereKey($ticket->id)->lockForUpdate()->first();
            if ($ticket->isClosed()) {
                throw ValidationException::withMessages(['body' => '這張工單已結案，請另外提出新的問題']);
            }

            $message = $ticket->messages()->create(['author_type' => 'player', 'body' => $body, 'created_at' => now()]);
            $from = $ticket->status;
            $ticket->update(['status' => 'open', 'last_message_at' => now()]);

            AuditLog::record('support.player_replied', $ticket, ['from' => $from, 'to' => 'open', 'actor_type' => 'player', 'actor' => (string) $ticket->player->game_player_id]);
            $this->notifySupport($ticket, $message, '玩家回覆');

            return $message;
        });
    }

    public function adminReply(SupportTicket $ticket, AdminUser $admin, string $body, bool $close): SupportMessage
    {
        $this->assertNoCardNumber($body, 'body');

        return DB::transaction(function () use ($ticket, $admin, $body, $close) {
            $ticket = SupportTicket::whereKey($ticket->id)->lockForUpdate()->first();
            $message = $ticket->messages()->create(['author_type' => 'admin', 'admin_user_id' => $admin->id, 'body' => $body, 'created_at' => now()]);
            $from = $ticket->status;
            $to = $close ? 'closed' : 'answered';
            $ticket->update(['status' => $to, 'last_message_at' => now(), 'closed_at' => $close ? now() : null]);

            AuditLog::record('support.replied', $ticket, ['from' => $from, 'to' => $to], $admin);
            if ($ticket->contact_email) {
                Mail::to($ticket->contact_email)->queue((new SupportReplyToPlayer($ticket, $message))->afterCommit());
            }

            return $message;
        });
    }

    public function close(SupportTicket $ticket, AdminUser $admin): void
    {
        DB::transaction(function () use ($ticket, $admin) {
            $ticket = SupportTicket::whereKey($ticket->id)->lockForUpdate()->first();
            if ($ticket->isClosed()) {
                return;
            }
            $from = $ticket->status;
            $ticket->update(['status' => 'closed', 'closed_at' => now()]);
            AuditLog::record('support.closed', $ticket, ['from' => $from, 'to' => 'closed'], $admin);
        });
    }

    /** 訊息裡不能出現卡號：13 到 19 位、通過 Luhn 檢查碼的數字（可含空格或連字號）。 */
    public function assertNoCardNumber(string $text, string $field): void
    {
        preg_match_all('/\d[\d \-]{11,30}\d/', $text, $matches);
        foreach ($matches[0] as $candidate) {
            $digits = MockCheckout::digits($candidate);
            if (strlen($digits) >= 13 && strlen($digits) <= 19 && MockCheckout::luhn($digits)) {
                throw ValidationException::withMessages([$field => '為了保護你的資料，請勿在訊息中填寫信用卡卡號']);
            }
        }
    }

    private function notifySupport(SupportTicket $ticket, SupportMessage $message, string $kind): void
    {
        Mail::to(config('topup.support_inbox'))->queue((new SupportTicketNotice($ticket, $message, $kind))->afterCommit());
    }
}
