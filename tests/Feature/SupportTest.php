<?php

namespace Tests\Feature;

use App\Mail\SupportReplyToPlayer;
use App\Mail\SupportTicketNotice;
use App\Models\AuditLog;
use App\Models\Player;
use App\Models\SupportTicket;
use App\Support\DemoGuard;
use App\Support\SsoToken;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SupportTest extends TestCase
{
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'category' => 'topup',
            'subject' => '付款後沒有入點',
            'body' => '我剛剛付款成功，但點數一直沒有入帳。',
            'contact_email' => 'alice@example.test',
        ];
    }

    private function shopPlayer(string $name = 'alice'): Player
    {
        return Player::forGame($this->player($name)->id);
    }

    public function test_player_creates_ticket_and_support_inbox_is_notified(): void
    {
        Mail::fake();
        $player = $this->shopPlayer();

        $response = $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form());

        $ticket = SupportTicket::sole();
        $response->assertRedirect(route('shop.support.show', $ticket));
        $this->assertSame(['open', 'topup', $player->id], [$ticket->status, $ticket->category, $ticket->player_id]);
        $this->assertMatchesRegularExpression('/^S\d{12}[A-Z0-9]{6}$/', $ticket->ticket_no);
        $this->assertSame('我剛剛付款成功，但點數一直沒有入帳。', $ticket->messages()->sole()->body);
        Mail::assertQueued(SupportTicketNotice::class, fn ($mail) => $mail->hasTo(config('topup.support_inbox')) && $mail->kind === '新工單');
        $this->assertSame(1, AuditLog::where('action', 'support.created')->count());

        $this->actingAs($player, 'shop')->get(route('shop.support.show', $ticket))
            ->assertOk()->assertSee('等待客服回覆')->assertSee('點數一直沒有入帳');
    }

    public function test_form_is_validated(): void
    {
        // 這裡要連續送出很多次，先關掉限流（限流另外測）。
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $player = $this->shopPlayer();
        $cases = [
            'category' => ['category' => 'gift'],
            'subject' => ['subject' => '短'],
            'body' => ['body' => '太短了'],
            'contact_email' => ['contact_email' => 'not-an-email'],
        ];
        foreach ($cases as $field => $override) {
            $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form($override))->assertSessionHasErrors($field);
        }

        // 別人的訂單不能掛在自己的工單上。
        $other = $this->order($this->player('bob'));
        $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form(['order_no' => $other->order_no]))
            ->assertSessionHasErrors(['order_no' => '找不到這筆訂單，請確認訂單編號']);

        // 卡號不能出現在訊息裡。
        $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form(['body' => '我的卡號是 4111-1111-1111-1111，請幫我查']))
            ->assertSessionHasErrors(['body' => '為了保護你的資料，請勿在訊息中填寫信用卡卡號']);
        $this->assertSame(0, SupportTicket::count());

        // 一般的長數字（例如訂單編號裡的數字）不受影響。
        $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form(['body' => '交易時間 20261106235258，請協助確認']))->assertSessionHasNoErrors();
        $this->assertSame(1, SupportTicket::count());
    }

    public function test_order_can_be_attached_and_failed_order_page_links_here(): void
    {
        $player = $this->shopPlayer();
        $order = $this->order($this->player('carol'));
        $order->update(['player_id' => $player->id, 'status' => 'failed']);

        $this->actingAs($player, 'shop')->get(route('shop.orders.show', $order->order_no))
            ->assertSee(route('shop.support.index', ['order' => $order->order_no, 'category' => 'topup']));
        $this->actingAs($player, 'shop')->get(route('shop.support.index', ['order' => $order->order_no, 'category' => 'topup']))
            ->assertOk()->assertSee('value="'.$order->order_no.'" selected', false);

        $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form(['order_no' => strtolower($order->order_no)]))->assertSessionHasNoErrors();
        $this->assertSame($order->order_no, SupportTicket::sole()->order_no);
    }

    public function test_admin_reply_notifies_player_and_closed_ticket_rejects_replies(): void
    {
        Mail::fake();
        $player = $this->shopPlayer();
        $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form());
        $ticket = SupportTicket::sole();
        $reviewer = $this->admin('reviewer');

        // 初審與覆核都能處理工單。
        $this->actingAs($reviewer, 'admin')->get(route('admin.support'))->assertOk()->assertSee($ticket->ticket_no);
        $this->actingAs($reviewer, 'admin')->post(route('admin.support.reply', $ticket), ['body' => '已幫你確認，點數已補入。'])
            ->assertRedirect(route('admin.support.show', $ticket));
        $this->assertSame('answered', $ticket->fresh()->status);
        Mail::assertQueued(SupportReplyToPlayer::class, fn ($mail) => $mail->hasTo('alice@example.test'));

        $this->actingAs($player, 'shop')->get(route('shop.support.show', $ticket))->assertSee('客服人員')->assertSee('已幫你確認，點數已補入。');

        // 玩家回覆後回到待處理，客服信箱再收到通知。
        $this->actingAs($player, 'shop')->post(route('shop.support.reply', $ticket), ['body' => '收到，謝謝！'])->assertSessionHasNoErrors();
        $this->assertSame('open', $ticket->fresh()->status);
        Mail::assertQueued(SupportTicketNotice::class, fn ($mail) => $mail->kind === '玩家回覆');

        $supervisor = $this->admin('supervisor');
        $this->actingAs($supervisor, 'admin')->post(route('admin.support.reply', $ticket), ['body' => '不客氣。', 'close' => '1']);
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->closed_at);

        $this->actingAs($player, 'shop')->post(route('shop.support.reply', $ticket), ['body' => '再問一個問題'])
            ->assertSessionHasErrors(['body' => '這張工單已結案，請另外提出新的問題']);
        $this->assertSame(4, $ticket->messages()->count());
        $this->assertSame(2, AuditLog::where('action', 'support.replied')->count());
    }

    public function test_ticket_without_email_sends_no_player_mail(): void
    {
        Mail::fake();
        $player = $this->shopPlayer();
        $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form(['contact_email' => '']));
        $ticket = SupportTicket::sole();
        $this->assertNull($ticket->contact_email);

        $this->actingAs($this->admin('reviewer'), 'admin')->post(route('admin.support.reply', $ticket), ['body' => '已處理']);
        Mail::assertNotQueued(SupportReplyToPlayer::class);

        $this->actingAs($this->admin('reviewer'), 'admin')->post(route('admin.support.close', $ticket))->assertRedirect();
        $this->assertSame('closed', $ticket->fresh()->status);
    }

    public function test_players_cannot_see_other_players_tickets(): void
    {
        $alice = $this->shopPlayer('alice');
        $this->actingAs($alice, 'shop')->post(route('shop.support.store'), $this->form());
        $ticket = SupportTicket::sole();
        $bob = $this->shopPlayer('bob');

        $this->actingAs($bob, 'shop')->get(route('shop.support.show', $ticket))->assertNotFound();
        $this->actingAs($bob, 'shop')->post(route('shop.support.reply', $ticket), ['body' => '我來亂的'])->assertNotFound();
        $this->actingAs($bob, 'shop')->get(route('shop.support.index'))->assertOk()->assertDontSee($ticket->ticket_no);
        $this->assertSame(1, $ticket->messages()->count());

        // 未登入不能使用。
        $this->app['auth']->forgetGuards();
        $this->get(route('shop.support.index'))->assertRedirect();
    }

    public function test_player_center_link_logs_into_shop_support(): void
    {
        $gamePlayer = $this->player();

        $this->actingAs($gamePlayer, 'game')->get(route('game.support'))
            ->assertOk()->assertSee('name="next" value="support"', false);

        $this->post(route('shop.sso'), ['token' => SsoToken::issue($gamePlayer->id), 'next' => 'support'])
            ->assertRedirect(route('shop.support.index'));
        // 其他目的地一律回方案頁，不做任意轉址。
        $this->post(route('shop.sso'), ['token' => SsoToken::issue($gamePlayer->id), 'next' => 'https://evil.example'])
            ->assertRedirect(route('shop.index'));
    }

    public function test_message_html_is_escaped_and_footer_lists_payment_methods(): void
    {
        $player = $this->shopPlayer();
        $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form(['body' => '<script>alert(1)</script> 這是測試內容']));
        $ticket = SupportTicket::sole();

        $html = $this->actingAs($player, 'shop')->get(route('shop.support.show', $ticket))->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        foreach (['visa', 'mastercard', 'jcb', 'amex'] as $brand) {
            $this->assertStringContainsString("/img/payments/{$brand}.svg", $html);
            $this->assertFileExists(public_path("img/payments/{$brand}.svg"));
        }
        $this->assertStringContainsString('聯繫客服支援', $html);
    }

    public function test_mail_can_only_go_to_local_mailpit(): void
    {
        DemoGuard::assertLocalMail('smtp', 'mailpit');
        DemoGuard::assertLocalMail('array', null);

        foreach ([['smtp', 'smtp.gmail.com'], ['ses', null], ['postmark', null]] as [$mailer, $host]) {
            try {
                DemoGuard::assertLocalMail($mailer, $host);
                $this->fail("$mailer $host 應該被擋下");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_ticket_creation_is_rate_limited(): void
    {
        $player = $this->shopPlayer();
        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form(['subject' => "第 {$i} 個問題"]))->assertRedirect();
        }
        $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form())->assertStatus(429);
        $this->assertSame(5, SupportTicket::count());
    }

    public function test_demo_reset_clears_tickets(): void
    {
        $player = $this->shopPlayer();
        $this->actingAs($player, 'shop')->post(route('shop.support.store'), $this->form());

        app(\App\Services\DemoTools::class)->reset($this->admin('supervisor'));

        $this->assertSame(0, SupportTicket::count());
    }
}
