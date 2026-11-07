<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\UseAdminSession;
use App\Models\AuditLog;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/** 後台與玩家端的 session 分開，後台有較短的閒置逾時與絕對期限。 */
class AdminSessionTest extends TestCase
{
    private function sessionCookie($response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (in_array($cookie->getName(), [config('session.cookie'), UseAdminSession::COOKIE], true)) {
                return $cookie;
            }
        }

        return null;
    }

    public function test_admin_uses_its_own_session_cookie_limited_to_admin_path(): void
    {
        $admin = $this->sessionCookie($this->get(route('admin.login'))->assertOk());
        $this->assertSame(UseAdminSession::COOKIE, $admin->getName());
        $this->assertSame('/admin', $admin->getPath());
        $this->assertTrue($admin->isSecure() && $admin->isHttpOnly());
        // 關閉瀏覽器就失效，不留持久 cookie。
        $this->assertSame(0, $admin->getExpiresTime());
    }

    // 測試裡同一個應用程式會沿用第一個請求建立的 session store，所以玩家端另外測。
    public function test_player_site_keeps_the_default_session_cookie(): void
    {
        $player = $this->sessionCookie($this->get(route('game.login'))->assertOk());
        $this->assertNotSame(UseAdminSession::COOKIE, $player->getName());
        $this->assertSame('/', $player->getPath());
    }

    public function test_admin_idle_timeout_is_shorter_than_players(): void
    {
        $this->get(route('admin.login'));

        $this->assertSame(15, config('session.lifetime'));
    }

    /** 玩家與後台共用 sessions 資料表；後台較短的逾時不能拿來清除玩家的 session。 */
    public function test_admin_requests_do_not_garbage_collect_sessions(): void
    {
        $this->get(route('admin.login'));
        $this->assertSame([0, 100], config('session.lottery'));
    }

    public function test_player_requests_keep_default_session_settings(): void
    {
        $default = config('session.lottery');
        $this->get(route('game.login'));

        $this->assertSame($default, config('session.lottery'));
        $this->assertSame((int) env('SESSION_LIFETIME', 120), (int) config('session.lifetime'));
    }

    public function test_admin_session_without_login_time_is_not_given_a_new_window(): void
    {
        $admin = $this->admin('reviewer');

        // be() 不經過 TOTP 登入，也沒有登入時間。
        $this->be($admin, 'admin');
        $this->get(route('admin.refunds'))->assertRedirect(route('admin.login'));
        $this->assertSame('沒有登入時間', AuditLog::where('action', 'admin.session_expired')->sole()->note);
    }

    public function test_expired_admin_session_data_is_discarded(): void
    {
        $admin = $this->admin('reviewer');

        $this->actingAs($admin, 'admin')->withSession([
            EnsureAdmin::AUTHENTICATED_AT => now()->subHours(9)->getTimestamp(),
            'url.intended' => route('admin.audit'),
        ])->get(route('admin.refunds'))->assertRedirect(route('admin.login'))->assertSessionMissing('url.intended');
    }

    public function test_admin_is_logged_out_after_absolute_limit(): void
    {
        $admin = $this->admin('reviewer');

        $this->actingAs($admin, 'admin')->withSession([EnsureAdmin::AUTHENTICATED_AT => now()->subHours(7)->getTimestamp()])
            ->get(route('admin.refunds'))->assertOk();

        $this->actingAs($admin, 'admin')->withSession([EnsureAdmin::AUTHENTICATED_AT => now()->subHours(8)->subMinute()->getTimestamp()])
            ->get(route('admin.refunds'))
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors(['username' => '登入已超過 8 小時，請重新登入']);
        $this->assertSame(1, AuditLog::where('action', 'admin.session_expired')->count());
    }
}
