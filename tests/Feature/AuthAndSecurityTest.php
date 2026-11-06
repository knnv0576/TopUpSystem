<?php

namespace Tests\Feature;

use App\DemoGame\Models\GamePlayer;
use App\Models\Player;
use App\Support\ApiSignature;
use App\Support\DemoGuard;
use App\Support\SsoToken;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthAndSecurityTest extends TestCase
{
    public function test_register_rejects_duplicate_username_but_allows_duplicate_nickname(): void
    {
        $form = ['password' => 'Password123456', 'password_confirmation' => 'Password123456', 'nickname' => '同名'];

        $this->post(route('game.register'), $form + ['username' => 'alice'])->assertRedirect(route('game.dashboard'));
        auth('game')->logout();
        $this->post(route('game.register'), $form + ['username' => 'bob'])->assertRedirect(route('game.dashboard'));
        auth('game')->logout();
        $this->from(route('game.register'))->post(route('game.register'), $form + ['username' => 'alice'])->assertSessionHasErrors('username');

        $this->assertSame(2, GamePlayer::where('nickname', '同名')->count());
    }

    public function test_register_enforces_password_strength_and_hashes_it(): void
    {
        $this->post(route('game.register'), ['username' => 'weak', 'nickname' => 'w', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $player = $this->player('alice');
        $this->assertNotSame('Password123456', $player->password);
    }

    public function test_sso_token_logs_in_once(): void
    {
        $player = $this->player();
        $token = SsoToken::issue($player->id);

        $this->post(route('shop.sso'), ['token' => $token])->assertRedirect(route('shop.index'));
        $this->assertAuthenticated('shop');
        auth('shop')->logout();

        $this->post(route('shop.sso'), ['token' => $token])->assertRedirect(route('game.login'));
        $this->assertGuest('shop');
    }

    public function test_sso_token_with_bad_signature_or_expired_is_rejected(): void
    {
        $player = $this->player();
        $token = SsoToken::issue($player->id);

        $this->post(route('shop.sso'), ['token' => $token.'x'])->assertRedirect(route('game.login'));
        $this->assertGuest('shop');

        $this->travel(61)->seconds();
        $this->post(route('shop.sso'), ['token' => $token])->assertRedirect(route('game.login'));
        $this->assertGuest('shop');
    }

    public function test_sso_token_cannot_forge_another_player(): void
    {
        $player = $this->player();
        [$payload] = explode('.', SsoToken::issue($player->id));
        $forged = rtrim(strtr(base64_encode(json_encode(['sub' => 999, 'jti' => 'x', 'exp' => time() + 60])), '+/', '-_'), '=');
        $this->assertNotSame($payload, $forged);

        $this->post(route('shop.sso'), ['token' => $forged.'.'.hash_hmac('sha256', $forged, 'wrong-secret')])
            ->assertRedirect(route('game.login'));
        $this->assertSame(0, Player::count());
    }

    public function test_admin_cannot_enter_without_totp_step(): void
    {
        $admin = $this->admin('reviewer');

        $this->get(route('admin.refunds'))->assertRedirect(route('admin.login'));

        $this->post(route('admin.login'), ['username' => $admin->username, 'password' => 'Password123456'])
            ->assertRedirect(route('admin.totp'));
        $this->assertGuest('admin');
        $this->get(route('admin.refunds'))->assertRedirect(route('admin.login'));

        $this->post(route('admin.totp'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest('admin');

        $this->post(route('admin.totp'), ['code' => $this->totp($admin)])->assertRedirect(route('admin.home'));
        $this->assertAuthenticated('admin');
        $this->get(route('admin.refunds'))->assertOk();
    }

    public function test_first_login_binds_totp_only_after_a_valid_code(): void
    {
        $admin = \App\Models\AdminUser::create(['username' => 'fresh', 'password' => 'Password123456', 'role' => 'reviewer']);

        $this->post(route('admin.login'), ['username' => 'fresh', 'password' => 'Password123456'])->assertRedirect(route('admin.totp'));

        $secret = $admin->fresh()->totp_secret;
        $page = $this->get(route('admin.totp'))->assertOk();
        $this->assertNotNull($admin->fresh()->totp_secret);
        // 綁定頁有 QR Code 與分組顯示的金鑰，且不能被快取。
        $this->assertStringContainsString('<svg', $page->getContent());
        $page->assertSee(trim(chunk_split($admin->fresh()->totp_secret, 4, ' ')));
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->assertStringContainsString('otpauth://totp/', app(\App\Services\TotpService::class)->provisioningUri($admin, $admin->fresh()->totp_secret));
        $this->assertSame($secret ?? $admin->fresh()->totp_secret, $admin->fresh()->totp_secret);
        $this->assertNull($admin->fresh()->totp_confirmed_at);
        $this->assertStringNotContainsString($admin->fresh()->totp_secret, (string) \Illuminate\Support\Facades\DB::table('admin_users')->where('id', $admin->id)->value('totp_secret'));

        $this->post(route('admin.totp'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNull($admin->fresh()->totp_confirmed_at);

        $this->post(route('admin.totp'), ['code' => $this->totp($admin)])->assertRedirect(route('admin.home'));
        $this->assertNotNull($admin->fresh()->totp_confirmed_at);
        $this->assertAuthenticated('admin');

        // 綁定完成後不再顯示密鑰。
        auth('admin')->logout();
        $this->post(route('admin.login'), ['username' => 'fresh', 'password' => 'Password123456']);
        $this->get(route('admin.totp'))->assertOk()->assertDontSee($admin->fresh()->totp_secret);
    }

    public function test_two_admins_can_finish_first_login_from_the_same_ip(): void
    {
        foreach (['reviewer', 'supervisor'] as $role) {
            $admin = $this->admin($role);
            $admin->update(['must_change_password' => true]);

            $this->from(route('admin.login'))->post(route('admin.login'), ['username' => $role, 'password' => 'wrong'])->assertSessionHasErrors('username');
            $this->post(route('admin.login'), ['username' => $role, 'password' => 'Password123456'])->assertRedirect(route('admin.totp'));
            $this->post(route('admin.totp'), ['code' => $this->totp($admin)])->assertRedirect(route('admin.home'));
            $this->post(route('admin.password'), ['current_password' => 'Password123456', 'password' => 'NewPassword98765', 'password_confirmation' => 'NewPassword98765'])
                ->assertRedirect(route('admin.home'));

            auth('admin')->logout();
        }
    }

    public function test_login_is_still_rate_limited_per_endpoint(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->from(route('game.login'))->post(route('game.login'), ['username' => 'nobody', 'password' => 'x'])->assertRedirect();
        }
        $this->post(route('game.login'), ['username' => 'nobody', 'password' => 'x'])->assertStatus(429);

        // 其他端點不受影響。
        $this->post(route('admin.login'), ['username' => 'nobody', 'password' => 'x'])->assertRedirect();
    }

    public function test_error_pages_are_localized_and_have_no_inline_styles(): void
    {
        config(['topup.admin_ip_allowlist' => ['10.9.8.7']]);

        $forbidden = $this->get(route('admin.login'))->assertForbidden()->getContent();
        $notFound = $this->get('/no-such-page')->assertNotFound()->getContent();

        foreach ([$forbidden, $notFound] as $html) {
            $this->assertStringContainsString('zh-Hant-TW', $html);
            $this->assertStringContainsString('/app.css', $html);
            $this->assertStringNotContainsString('<style', $html);
            $this->assertStringNotContainsString('style="', $html);
        }
        $this->assertStringContainsString('沒有權限', $forbidden);
        $this->assertStringContainsString('找不到', $notFound);
    }

    public function test_totp_code_cannot_be_replayed(): void
    {
        $admin = $this->admin('supervisor');
        $code = $this->totp($admin);
        $service = app(\App\Services\TotpService::class);

        $this->assertTrue($service->verify($admin, $code));
        $this->assertFalse($service->verify($admin, $code));
    }

    public function test_wrong_admin_password_is_rejected(): void
    {
        $admin = $this->admin('reviewer');
        $this->from(route('admin.login'))->post(route('admin.login'), ['username' => $admin->username, 'password' => 'nope'])
            ->assertSessionHasErrors('username');
        $this->assertNull(session('admin_pending_id'));
    }

    public function test_admin_ip_allowlist_blocks_other_sources_and_ignores_forwarded_header(): void
    {
        config(['topup.admin_ip_allowlist' => ['10.9.8.7']]);

        $this->get(route('admin.login'))->assertForbidden();
        $this->withHeader('X-Forwarded-For', '10.9.8.7')->get(route('admin.login'))->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->get(route('admin.login'))->assertOk();
    }

    public function test_internal_api_requires_valid_signature_and_rejects_replay(): void
    {
        $player = $this->player();
        $path = "/api/game/players/{$player->id}/grant";
        $body = json_encode(['points' => 100, 'key' => 'k1']);

        $call = fn (array $headers) => $this->call('POST', $path, [], [], [], $this->server($headers), $body);

        $call([])->assertStatus(401);
        $call(ApiSignature::headers('wrong', 'POST', $path, $body))->assertStatus(401);

        $good = ApiSignature::headers(config('topup.game_api_secret'), 'POST', $path, $body);
        $call($good)->assertOk();
        $this->assertSame([100, 0], $this->wallet($player));
        $call($good)->assertStatus(401);

        $old = ApiSignature::headers(config('topup.game_api_secret'), 'POST', $path, $body);
        $this->travel(10)->minutes();
        $call($old)->assertStatus(401);

        // 簽章涵蓋內容：竄改內容就失效。
        $tampered = ApiSignature::headers(config('topup.game_api_secret'), 'POST', $path, $body);
        $this->call('POST', $path, [], [], [], $this->server($tampered), json_encode(['points' => 999, 'key' => 'k2']))->assertStatus(401);
        $this->assertSame(100, $this->wallet($player)[0]);
    }

    public function test_api_secrets_are_not_interchangeable(): void
    {
        $player = $this->player();
        $path = "/api/game/players/{$player->id}/grant";
        $body = json_encode(['points' => 100, 'key' => 'k1']);

        $headers = ApiSignature::headers(config('topup.topup_api_secret'), 'POST', $path, $body);
        $this->call('POST', $path, [], [], [], $this->server($headers), $body)->assertStatus(401);
    }

    public function test_production_environment_cannot_boot(): void
    {
        DemoGuard::assertNotProduction('local');

        $this->expectException(\RuntimeException::class);
        DemoGuard::assertNotProduction('production');
    }

    public function test_audit_log_cannot_be_updated_or_deleted(): void
    {
        $log = \App\Models\AuditLog::record('test.action');

        foreach ([
            fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'x']),
            fn () => DB::table('audit_logs')->where('id', $log->id)->delete(),
        ] as $attempt) {
            DB::beginTransaction();
            try {
                $attempt();
                $this->fail('audit log should be append-only');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            } finally {
                DB::rollBack();
            }
        }
    }

    private function server(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            if (strtolower($name) !== 'content-type') {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }
        }

        return $server;
    }
}
