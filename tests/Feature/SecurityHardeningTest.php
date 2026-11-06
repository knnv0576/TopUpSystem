<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Services\DemoTools;
use App\Services\TotpService;
use App\Support\SsoToken;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 這裡測的是帳號鎖定，不是 IP 限流。
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_audit_log_survives_demo_reset_and_cannot_be_truncated(): void
    {
        $admin = $this->admin('supervisor');
        $old = AuditLog::record('test.before_reset', null, ['note' => '重置前']);
        $this->player('alice', 1000);

        app(DemoTools::class)->reset($admin);

        $this->assertSame(0, \App\DemoGame\Models\GamePlayer::count());
        $this->assertTrue(AuditLog::whereKey($old->id)->exists());
        $this->assertSame(1, AuditLog::where('action', 'demo.reset')->count());

        DB::beginTransaction();
        try {
            DB::statement('TRUNCATE TABLE audit_logs');
            $this->fail('TRUNCATE should be blocked');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_reset_does_not_reuse_ids_referenced_by_old_audit_logs(): void
    {
        $first = $this->player('alice');
        app(DemoTools::class)->reset($this->admin('supervisor'));

        $this->assertGreaterThan($first->id, $this->player('bob')->id);
    }

    public function test_admin_authentication_events_are_audited_with_source_ip(): void
    {
        $admin = $this->admin('reviewer');

        $this->from(route('admin.login'))->post(route('admin.login'), ['username' => 'reviewer', 'password' => 'wrong']);
        $this->from(route('admin.login'))->post(route('admin.login'), ['username' => 'typed-my-Secret-here', 'password' => 'wrong']);

        $failed = AuditLog::where('action', 'admin.login_failed')->orderBy('id')->get();
        $this->assertCount(2, $failed);
        $this->assertSame('reviewer', $failed[0]->actor);
        $this->assertSame('127.0.0.1', $failed[0]->ip);
        $this->assertNull($failed[1]->actor);
        // 帳號不存在時不寫入輸入內容：有人可能把密碼打在帳號欄。
        $this->assertStringNotContainsString('typed-my-Secret-here', json_encode(AuditLog::all()));

        $this->post(route('admin.login'), ['username' => 'reviewer', 'password' => 'Password123456']);
        $this->post(route('admin.totp'), ['code' => '000000']);
        $this->assertSame(1, AuditLog::where('action', 'admin.totp_failed')->count());

        $this->post(route('admin.totp'), ['code' => $this->totp($admin)]);
        $this->assertSame(1, AuditLog::where('action', 'admin.login')->count());
        $this->assertNotNull($admin->fresh()->last_login_at);

        $this->post(route('admin.logout'));
        $this->assertSame(1, AuditLog::where('action', 'admin.logout')->count());
        $this->assertGuest('admin');
    }

    public function test_failed_step_up_totp_is_audited(): void
    {
        $supervisor = $this->admin('supervisor');
        $refund = $this->submitRefund($this->player('alice', 50000));
        app(\App\Services\RefundService::class)->reviewApprove($refund, $this->admin('reviewer'));

        $this->actingAs($supervisor, 'admin')->post(route('admin.refunds.approve', $refund), ['code' => '000000']);

        $this->assertSame(1, AuditLog::where('action', 'admin.totp_failed')->where('actor', 'supervisor')->count());
    }

    public function test_sso_events_are_audited(): void
    {
        $player = $this->player();
        $token = SsoToken::issue($player->id);

        $this->post(route('shop.sso'), ['token' => $token]);
        $this->assertSame(1, AuditLog::where('action', 'shop.login')->count());
        auth('shop')->logout();

        $this->post(route('shop.sso'), ['token' => $token]);
        $this->post(route('shop.sso'), ['token' => 'forged.token']);
        $this->assertSame(2, AuditLog::where('action', 'shop.sso_failed')->count());
    }

    public function test_player_security_events_go_to_security_log_without_passwords(): void
    {
        $channel = Log::spy();
        Log::shouldReceive('channel')->with('security')->andReturn($channel);
        $player = $this->player('alice');

        $this->from(route('game.login'))->post(route('game.login'), ['username' => 'alice', 'password' => 'wrong-password-1']);
        $this->post(route('game.login'), ['username' => 'alice', 'password' => 'Password123456']);
        $this->post(route('game.logout'));

        $channel->shouldHaveReceived('info')->with('player.login_failed', \Mockery::on(fn ($c) => $c['player_no'] === $player->player_no && ! str_contains(json_encode($c), 'wrong-password-1')));
        $channel->shouldHaveReceived('info')->with('player.login', \Mockery::type('array'));
        $channel->shouldHaveReceived('info')->with('player.logout', \Mockery::type('array'));
    }

    public function test_admin_is_locked_after_repeated_password_failures(): void
    {
        $admin = $this->admin('reviewer');

        for ($i = 0; $i < 5; $i++) {
            $this->from(route('admin.login'))->post(route('admin.login'), ['username' => 'reviewer', 'password' => 'wrong']);
        }
        $this->assertTrue($admin->fresh()->isLockedOut());
        $this->assertSame(1, AuditLog::where('action', 'admin.locked')->count());

        // 鎖定期間，正確密碼也進不去。
        $this->from(route('admin.login'))->post(route('admin.login'), ['username' => 'reviewer', 'password' => 'Password123456'])->assertSessionHasErrors('username');
        $this->assertNull(session('admin_pending_id'));

        $this->travel(16)->minutes();
        $this->post(route('admin.login'), ['username' => 'reviewer', 'password' => 'Password123456'])->assertRedirect(route('admin.totp'));
    }

    public function test_correct_password_does_not_reset_totp_failure_count(): void
    {
        $admin = $this->admin('reviewer');

        for ($round = 0; $round < 5; $round++) {
            $this->post(route('admin.login'), ['username' => 'reviewer', 'password' => 'Password123456']);
            $this->post(route('admin.totp'), ['code' => '000000']);
        }

        $this->assertTrue($admin->fresh()->isLockedOut());
        $this->assertFalse(app(TotpService::class)->verify($admin, $this->totp($admin)), '鎖定時正確的驗證碼也不接受');
    }

    public function test_success_clears_failures(): void
    {
        $admin = $this->admin('reviewer');
        $this->from(route('admin.login'))->post(route('admin.login'), ['username' => 'reviewer', 'password' => 'wrong']);
        $this->assertSame(1, $admin->fresh()->failed_attempts);

        $this->post(route('admin.login'), ['username' => 'reviewer', 'password' => 'Password123456']);
        $this->assertSame(1, $admin->fresh()->failed_attempts, '只通過密碼還不算成功');

        $this->post(route('admin.totp'), ['code' => $this->totp($admin)]);
        $this->assertSame(0, $admin->fresh()->failed_attempts);
    }

    public function test_lockout_ends_existing_admin_session(): void
    {
        $admin = $this->admin('reviewer');
        $this->actingAs($admin, 'admin')->get(route('admin.refunds'))->assertOk();

        AdminUser::whereKey($admin->id)->update(['locked_until' => now()->addMinutes(10)]);
        auth('admin')->forgetUser();

        $this->get(route('admin.refunds'))->assertRedirect(route('admin.login'));
    }

    public function test_player_is_locked_after_repeated_failures(): void
    {
        $player = $this->player('alice');

        for ($i = 0; $i < 5; $i++) {
            $this->from(route('game.login'))->post(route('game.login'), ['username' => 'alice', 'password' => 'wrong']);
        }
        $this->assertTrue($player->fresh()->isLockedOut());

        $this->from(route('game.login'))->post(route('game.login'), ['username' => 'alice', 'password' => 'Password123456'])->assertSessionHasErrors('username');
        $this->assertGuest('game');

        $this->travel(16)->minutes();
        $this->post(route('game.login'), ['username' => 'alice', 'password' => 'Password123456'])->assertRedirect(route('game.dashboard'));
        $this->assertSame(0, $player->fresh()->failed_attempts);
    }

    public function test_seeded_admins_have_distinct_random_passwords_and_must_change_them(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = AdminUser::where('username', 'reviewer')->firstOrFail();
        $supervisor = AdminUser::where('username', 'supervisor')->firstOrFail();

        $this->assertTrue($reviewer->must_change_password);
        $this->assertTrue($supervisor->must_change_password);
        $this->assertNotSame($reviewer->password, $supervisor->password);
        $this->assertNull(env('ADMIN_SEED_PASSWORD'), '.env 不應再保存管理員密碼');
    }

    public function test_must_change_password_blocks_everything_else(): void
    {
        $admin = $this->admin('supervisor');
        $admin->update(['must_change_password' => true]);
        $this->actingAs($admin->fresh(), 'admin');

        foreach ([route('admin.refunds'), route('admin.audit'), route('admin.accounts'), route('admin.demo')] as $url) {
            $this->get($url)->assertRedirect(route('admin.password'));
        }
        $this->post(route('admin.demo.next-payout'), ['result' => 'failed'])->assertRedirect(route('admin.password'));

        $this->get(route('admin.password'))->assertOk()->assertSee('初始密碼');

        // 弱密碼、相同密碼、錯誤的目前密碼都不接受。
        $this->post(route('admin.password'), ['current_password' => 'Password123456', 'password' => 'short1', 'password_confirmation' => 'short1'])->assertSessionHasErrors('password');
        $this->post(route('admin.password'), ['current_password' => 'Password123456', 'password' => 'Password123456', 'password_confirmation' => 'Password123456'])->assertSessionHasErrors('password');
        $this->post(route('admin.password'), ['current_password' => 'wrong', 'password' => 'NewPassword98765', 'password_confirmation' => 'NewPassword98765'])->assertSessionHasErrors('current_password');
        $this->assertTrue($admin->fresh()->must_change_password);

        $this->post(route('admin.password'), ['current_password' => 'Password123456', 'password' => 'NewPassword98765', 'password_confirmation' => 'NewPassword98765'])->assertRedirect(route('admin.home'));

        $fresh = $admin->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check('NewPassword98765', $fresh->password));
        $this->assertNotNull($fresh->password_changed_at);
        $this->get(route('admin.refunds'))->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'admin.password_changed')->count());
    }

    private function loginThroughForms(AdminUser $admin, string $password = 'Password123456'): void
    {
        $this->post(route('admin.login'), ['username' => $admin->username, 'password' => $password])->assertRedirect(route('admin.totp'));
        $this->post(route('admin.totp'), ['code' => $this->totp($admin)])->assertRedirect(route('admin.home'));
        $this->get(route('admin.refunds'))->assertOk();
    }

    public function test_password_change_elsewhere_ends_other_sessions(): void
    {
        $admin = $this->admin('reviewer');
        $this->loginThroughForms($admin);

        // 另一台裝置改了密碼：這裡的 session 持有舊指紋。
        $admin->fresh()->update(['password' => 'ChangedElsewhere123']);
        auth('admin')->forgetUser();

        $this->get(route('admin.refunds'))->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
        $this->assertSame(1, AuditLog::where('action', 'admin.session_invalidated')->count());

        // 舊 session 不能再回來；用新密碼重新登入則正常。
        $this->get(route('admin.refunds'))->assertRedirect(route('admin.login'));
        $this->loginThroughForms($admin, 'ChangedElsewhere123');
    }

    public function test_current_device_stays_logged_in_after_changing_password(): void
    {
        $admin = $this->admin('reviewer');
        $this->loginThroughForms($admin);

        $this->post(route('admin.password'), ['current_password' => 'Password123456', 'password' => 'NewPassword98765', 'password_confirmation' => 'NewPassword98765'])
            ->assertRedirect(route('admin.home'));
        auth('admin')->forgetUser();

        // 提示訊息要撐過 /admin 的轉址，顯示在下一頁。
        $this->get(route('admin.home'))->assertRedirect(route('admin.refunds'));
        $this->get(route('admin.refunds'))->assertOk()->assertSee('密碼已更新');
        $this->assertSame(0, AuditLog::where('action', 'admin.session_invalidated')->count());
    }

    public function test_cli_reset_ends_existing_sessions(): void
    {
        foreach (['--password', '--totp'] as $option) {
            $admin = $this->admin('reviewer');
            AdminUser::whereKey($admin->id)->update([
                'password' => Hash::make('Password123456'),
                'must_change_password' => false,
                'totp_secret' => encrypt((new \PragmaRX\Google2FA\Google2FA)->generateSecretKey(32), false),
                'totp_confirmed_at' => now()->subMinute(),
            ]);
            $this->loginThroughForms($admin->fresh());

            Artisan::call('topup:admin-reset', ['username' => 'reviewer', $option => true]);
            auth('admin')->forgetUser();

            $this->get(route('admin.refunds'))->assertRedirect(route('admin.login'));
            $this->assertGuest('admin');
        }
    }

    public function test_admin_kicked_session_sees_the_required_message(): void
    {
        $admin = $this->admin('reviewer');
        $this->loginThroughForms($admin);
        $admin->fresh()->update(['password' => 'ChangedElsewhere123']);
        auth('admin')->forgetUser();

        $this->followingRedirects()->get(route('admin.refunds'))->assertOk()->assertSee('帳號密碼已變更，請重新登入');
    }

    public function test_wrong_current_password_counts_toward_admin_lockout(): void
    {
        $admin = $this->admin('reviewer');
        $this->actingAs($admin, 'admin');
        $form = ['current_password' => 'wrong', 'password' => 'NewPassword98765', 'password_confirmation' => 'NewPassword98765'];

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.password'), $form);
            auth('admin')->forgetUser();
            $this->actingAs($admin->fresh(), 'admin');
        }

        $this->assertTrue($admin->fresh()->isLockedOut());
        $this->get(route('admin.refunds'))->assertRedirect(route('admin.login'));
    }

    private function loginPlayer(string $username, string $password = 'Password123456'): void
    {
        $this->post(route('game.login'), ['username' => $username, 'password' => $password])->assertRedirect(route('game.dashboard'));
    }

    public function test_player_can_change_password_with_validation(): void
    {
        $player = $this->player('alice');
        $this->loginPlayer('alice');
        $this->get(route('game.password'))->assertOk();

        $this->post(route('game.password.update'), ['current_password' => 'Password123456', 'password' => 'short1', 'password_confirmation' => 'short1'])->assertSessionHasErrors('password');
        $this->post(route('game.password.update'), ['current_password' => 'Password123456', 'password' => 'Password123456', 'password_confirmation' => 'Password123456'])->assertSessionHasErrors('password');
        $this->post(route('game.password.update'), ['current_password' => 'Password123456', 'password' => 'NewPassword987', 'password_confirmation' => 'Different987654'])->assertSessionHasErrors('password');
        $this->post(route('game.password.update'), ['current_password' => 'wrong', 'password' => 'NewPassword987', 'password_confirmation' => 'NewPassword987'])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('Password123456', $player->fresh()->password));

        $this->post(route('game.password.update'), ['current_password' => 'Password123456', 'password' => 'NewPassword987', 'password_confirmation' => 'NewPassword987'])
            ->assertRedirect(route('game.dashboard'))->assertSessionHas('status', '密碼已更新');
        auth('game')->forgetUser();

        // 這台裝置保持登入；新密碼可用，舊密碼不可用。
        $this->get(route('game.dashboard'))->assertOk();
        $this->assertTrue(Hash::check('NewPassword987', $player->fresh()->password));
        $this->post(route('game.logout'));
        $this->from(route('game.login'))->post(route('game.login'), ['username' => 'alice', 'password' => 'Password123456'])->assertSessionHasErrors('username');
        $this->loginPlayer('alice', 'NewPassword987');
    }

    public function test_player_password_change_ends_other_game_sessions(): void
    {
        $player = $this->player('alice');
        $this->loginPlayer('alice');
        $this->get(route('game.dashboard'))->assertOk();

        // 另一台裝置改了密碼。
        $player->fresh()->update(['password' => 'ChangedElsewhere123']);
        auth('game')->forgetUser();

        $this->followingRedirects()->get(route('game.dashboard'))->assertOk()->assertSee('帳號密碼已變更，請重新登入');
        $this->assertGuest('game');
    }

    public function test_player_password_change_revokes_shop_sessions(): void
    {
        $player = $this->player('alice');
        $this->post(route('shop.sso'), ['token' => SsoToken::issue($player->id)]);
        $this->get(route('shop.index'))->assertOk();
        $this->travel(2)->seconds();

        $this->withSession([]);
        $this->actingAs($player, 'game')->post(route('game.password.update'), ['current_password' => 'Password123456', 'password' => 'NewPassword987', 'password_confirmation' => 'NewPassword987']);
        $shopPlayer = \App\Models\Player::where('game_player_id', $player->id)->firstOrFail();
        $this->assertNotNull($shopPlayer->sessions_revoked_at);
        $this->assertSame(1, AuditLog::where('action', 'player.sessions_revoked')->count());

        // 模擬另一台裝置：充值網站 session 是改密碼之前建立的。
        auth('shop')->login($shopPlayer);
        session()->put(\App\Http\Middleware\EnsureShopPlayer::LOGIN_AT_KEY, now()->subMinute()->timestamp);
        $this->get(route('shop.index'))->assertRedirect(route('game.login'))->assertSessionHasErrors('username');
        $this->assertGuest('shop');

        // 重新從玩家中心進入就可以用。
        $this->travel(2)->seconds();
        $this->post(route('shop.sso'), ['token' => SsoToken::issue($player->id)])->assertRedirect(route('shop.index'));
        $this->get(route('shop.index'))->assertOk();
    }

    public function test_shop_revocation_retries_when_topup_is_unavailable(): void
    {
        $player = $this->player('alice');
        \Illuminate\Support\Facades\Queue::fake();
        $this->failingPaths = ['/api/topup/players/*/revoke-sessions'];

        $this->actingAs($player, 'game')->post(route('game.password.update'), ['current_password' => 'Password123456', 'password' => 'NewPassword987', 'password_confirmation' => 'NewPassword987'])
            ->assertRedirect(route('game.dashboard'));

        $this->assertTrue(Hash::check('NewPassword987', $player->fresh()->password));
        \Illuminate\Support\Facades\Queue::assertPushed(\App\DemoGame\Jobs\RevokeShopSessions::class);

        $this->failingPaths = [];
        (new \App\DemoGame\Jobs\RevokeShopSessions($player->id))->handle(app(\App\DemoGame\Services\TopupClient::class));
        $this->assertNotNull(\App\Models\Player::where('game_player_id', $player->id)->value('sessions_revoked_at'));
    }

    public function test_wrong_current_password_locks_player_changes_without_ending_the_session(): void
    {
        $player = $this->player('alice');
        $this->loginPlayer('alice');
        $form = ['current_password' => 'wrong', 'password' => 'NewPassword987', 'password_confirmation' => 'NewPassword987'];

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('game.password.update'), $form);
        }
        $this->assertTrue($player->fresh()->isLockedOut());

        // 鎖定期間，正確的目前密碼也不接受。
        $this->post(route('game.password.update'), ['current_password' => 'Password123456'] + $form)->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('Password123456', $player->fresh()->password));

        // 不踢出登入中的玩家：否則別人只要打錯幾次密碼，就能把他踢下線。
        $this->get(route('game.dashboard'))->assertOk();
    }

    public function test_supervisor_can_disable_and_enable_accounts_with_totp(): void
    {
        $reviewer = $this->admin('reviewer');
        $supervisor = $this->admin('supervisor');

        $this->actingAs($reviewer, 'admin')->get(route('admin.accounts'))->assertForbidden();
        $this->actingAs($reviewer, 'admin')->post(route('admin.accounts.disable', $supervisor), ['reason' => 'x', 'code' => '123456'])->assertForbidden();

        $this->actingAs($supervisor, 'admin')->get(route('admin.accounts'))->assertOk()->assertSee('reviewer');
        $this->post(route('admin.accounts.disable', $reviewer), ['reason' => '離職', 'code' => '000000'])->assertSessionHasErrors('code');
        $this->post(route('admin.accounts.disable', $reviewer), ['code' => $this->totp($supervisor)])->assertSessionHasErrors('reason');
        $this->assertFalse($reviewer->fresh()->isDisabled());

        $this->post(route('admin.accounts.disable', $supervisor), ['reason' => '自己', 'code' => $this->totp($supervisor)])->assertSessionHasErrors('action');
        $this->assertFalse($supervisor->fresh()->isDisabled());

        $this->post(route('admin.accounts.disable', $reviewer), ['reason' => '離職', 'code' => $this->totp($supervisor)]);
        $this->assertTrue($reviewer->fresh()->isDisabled());
        $this->assertSame(1, AuditLog::where('action', 'admin.disabled')->where('note', '離職')->count());

        // 既有 session 立即失效，也不能重新登入。
        auth('admin')->logout();
        $this->actingAs($reviewer->fresh(), 'admin')->get(route('admin.refunds'))->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
        $this->from(route('admin.login'))->post(route('admin.login'), ['username' => 'reviewer', 'password' => 'Password123456'])->assertSessionHasErrors('username');

        $this->actingAs($supervisor->fresh(), 'admin')->post(route('admin.accounts.enable', $reviewer), ['reason' => '復職', 'code' => $this->totp($supervisor)]);
        $this->assertFalse($reviewer->fresh()->isDisabled());
    }

    public function test_cli_reset_clears_lock_and_requires_password_change(): void
    {
        $admin = $this->admin('reviewer');
        $admin->update(['locked_until' => now()->addMinutes(10), 'failed_attempts' => 3]);

        $this->assertSame(1, Artisan::call('topup:admin-reset', ['username' => 'nobody', '--password' => true]));
        $this->assertSame(1, Artisan::call('topup:admin-reset', ['username' => 'reviewer']));

        $this->assertSame(0, Artisan::call('topup:admin-reset', ['username' => 'reviewer', '--password' => true, '--totp' => true]));
        preg_match('/password: (\S+)/', Artisan::output(), $match);

        $fresh = $admin->fresh();
        $this->assertTrue(Hash::check($match[1], $fresh->password));
        $this->assertTrue($fresh->must_change_password);
        $this->assertNull($fresh->totp_secret);
        $this->assertNull($fresh->totp_confirmed_at);
        $this->assertFalse($fresh->isLockedOut());

        $log = AuditLog::where('action', 'admin.reset_by_cli')->firstOrFail();
        $this->assertSame('cli', $log->actor_type);
        $this->assertStringNotContainsString($match[1], json_encode($log));
    }

    public function test_exceptions_do_not_carry_function_arguments(): void
    {
        $this->assertSame('1', ini_get('zend.exception_ignore_args'));

        $leak = static fn (string $secret) => throw new \RuntimeException('x');
        try {
            $leak('1234567890123456');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('123456789012', $e->getTraceAsString());
        }
    }
}
