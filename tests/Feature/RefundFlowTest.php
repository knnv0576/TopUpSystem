<?php

namespace Tests\Feature;

use App\DemoGame\Models\GamePlayer;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\RefundRequest;
use App\Payments\MockGateway;
use App\Services\RefundService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RefundFlowTest extends TestCase
{
    private function service(): RefundService
    {
        return app(RefundService::class);
    }

    /** 走完兩層審核；核准後同步佇列會完成扣點與撥款。 */
    private function approveThrough(RefundRequest $refund): RefundRequest
    {
        $this->service()->reviewApprove($refund, $this->admin('reviewer'));
        $this->service()->supervisorApprove($refund->fresh(), $this->admin('supervisor'));

        return $refund->fresh();
    }

    public function test_happy_path_freezes_deducts_and_pays_out(): void
    {
        $player = $this->player('alice', 50000);

        $refund = $this->submitRefund($player, 20000);
        $this->assertSame('pending_review', $refund->status);
        $this->assertSame(200, $refund->amount);
        $this->assertSame([50000, 20000], $this->wallet($player));

        $refund = $this->approveThrough($refund);

        $this->assertSame('paid_out', $refund->status);
        $this->assertSame([30000, 0], $this->wallet($player));
        $this->assertSame(200, (int) DB::table('mock_payouts')->value('amount'));
        $this->assertLedgerConsistent();
    }

    public function test_player_who_never_paid_can_cash_out_at_fixed_rate(): void
    {
        $player = $this->player('free', 100000);

        $refund = $this->approveThrough($this->submitRefund($player, 100000));

        $this->assertSame('paid_out', $refund->status);
        $this->assertSame(1000, $refund->amount);
        $this->assertSame(0, DB::table('payments')->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPoints')]
    public function test_invalid_points_are_rejected(int $points): void
    {
        $player = $this->player('alice', 100000);

        $this->expectException(RuntimeException::class);
        $this->submitRefund($player, $points);
    }

    public static function invalidPoints(): array
    {
        return ['below minimum' => [9900], 'not a multiple' => [10050], 'zero' => [0]];
    }

    public function test_insufficient_available_points_ends_in_rejection(): void
    {
        $player = $this->player('alice', 9999);

        $refund = $this->submitRefund($player, 10000);

        $this->assertSame('rejected', $refund->status);
        $this->assertSame('可用點數不足', $refund->reject_reason);
        $this->assertSame([9999, 0], $this->wallet($player));
    }

    public function test_only_one_open_request_per_player(): void
    {
        $player = $this->player('alice', 100000);
        $this->submitRefund($player, 10000);

        try {
            $this->submitRefund($player, 10000);
            $this->fail('second open request should fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('進行中', $e->getMessage());
        }
        $this->assertSame([100000, 10000], $this->wallet($player));
    }

    public function test_same_game_request_id_returns_the_same_request(): void
    {
        $player = $this->player('alice', 100000);
        $id = (string) Str::uuid();

        $a = $this->service()->submit($id, $player->id, 10000, '812', '1234567890');
        $b = $this->service()->submit($id, $player->id, 10000, '812', '1234567890');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, RefundRequest::count());
    }

    public function test_rejection_unfreezes_and_allows_resubmission(): void
    {
        $player = $this->player('alice', 50000);
        $refund = $this->submitRefund($player, 20000);

        $this->service()->reviewReject($refund, $this->admin('reviewer'), '資料不符');

        $this->assertSame('rejected', $refund->fresh()->status);
        $this->assertSame([50000, 0], $this->wallet($player));

        $again = $this->submitRefund($player, 20000);
        $this->assertSame('pending_review', $again->status);
        $this->service()->reviewApprove($again, $this->admin('reviewer'));
        $this->service()->supervisorReject($again->fresh(), $this->admin('supervisor'), '覆核不同意');

        $this->assertSame('rejected', $again->fresh()->status);
        $this->assertSame([50000, 0], $this->wallet($player));
        $this->assertLedgerConsistent();
    }

    public function test_roles_are_separated(): void
    {
        $refund = $this->submitRefund($this->player('alice', 50000));
        $reviewer = $this->admin('reviewer');
        $supervisor = $this->admin('supervisor');

        foreach ([
            fn () => $this->service()->reviewApprove($refund, $supervisor),
            fn () => $this->service()->supervisorApprove($refund, $reviewer),
            fn () => $this->service()->supervisorApprove($refund, $supervisor), // 尚未初審
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('should be rejected');
            } catch (RuntimeException) {
            }
        }
        $this->assertSame('pending_review', $refund->fresh()->status);

        $this->service()->reviewApprove($refund, $reviewer);
        try {
            $this->service()->reviewApprove($refund->fresh(), $reviewer);
            $this->fail('cannot review twice');
        } catch (RuntimeException) {
        }
    }

    public function test_routes_enforce_role_and_totp(): void
    {
        $refund = $this->submitRefund($this->player('alice', 50000));
        $reviewer = $this->admin('reviewer');
        $supervisor = $this->admin('supervisor');

        // 角色錯誤：路由層 403。
        $this->actingAs($supervisor, 'admin')->post(route('admin.refunds.review-approve', $refund))->assertForbidden();
        $this->actingAs($reviewer, 'admin')->post(route('admin.refunds.approve', $refund), ['code' => '123456'])->assertForbidden();

        $this->actingAs($reviewer, 'admin')->post(route('admin.refunds.review-approve', $refund))->assertRedirect();
        $this->assertSame('pending_supervisor', $refund->fresh()->status);

        // 沒有或錯誤的 TOTP：不核准。
        $this->actingAs($supervisor, 'admin')->post(route('admin.refunds.approve', $refund), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertSame('pending_supervisor', $refund->fresh()->status);

        $this->actingAs($supervisor, 'admin')->post(route('admin.refunds.approve', $refund), ['code' => $this->totp($supervisor)])->assertRedirect();
        $this->assertSame('paid_out', $refund->fresh()->status);
    }

    public function test_admin_cannot_change_amount(): void
    {
        $refund = $this->submitRefund($this->player('alice', 50000), 20000);
        $reviewer = $this->admin('reviewer');
        $supervisor = $this->admin('supervisor');

        $this->actingAs($reviewer, 'admin')->post(route('admin.refunds.review-approve', $refund), ['amount' => 1, 'points' => 1]);
        $this->actingAs($supervisor, 'admin')->post(route('admin.refunds.approve', $refund), ['code' => $this->totp($supervisor), 'amount' => 9999999]);

        $refund->refresh();
        $this->assertSame(200, $refund->amount);
        $this->assertSame(20000, $refund->points);
        $this->assertSame(200, (int) DB::table('mock_payouts')->value('amount'));
    }

    public function test_database_rejects_invalid_points_and_same_person_review(): void
    {
        $refund = $this->submitRefund($this->player('alice', 50000));
        $admin = $this->admin('supervisor');

        foreach ([
            ['points' => 10050, 'amount' => 100],
            ['amount' => 1],
            ['reviewer_id' => $admin->id, 'supervisor_id' => $admin->id],
        ] as $change) {
            DB::beginTransaction();
            try {
                DB::table('refund_requests')->where('id', $refund->id)->update($change);
                $this->fail('check constraint should fail');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_double_approval_pays_once(): void
    {
        $player = $this->player('alice', 50000);
        $refund = $this->approveThrough($this->submitRefund($player, 20000));

        try {
            $this->service()->supervisorApprove($refund, $this->admin('supervisor'));
            $this->fail('second approval should fail');
        } catch (RuntimeException) {
        }
        // 重跑工作也不會有任何影響。
        $this->service()->execute($refund->id);

        $this->assertSame(1, DB::table('mock_payouts')->count());
        $this->assertSame(1, (int) DB::table('mock_payouts')->value('attempts'));
        $this->assertSame([30000, 0], $this->wallet($player));
    }

    public function test_payout_failure_then_retry_pays_once(): void
    {
        $player = $this->player('alice', 50000);
        Cache::put(MockGateway::NEXT_PAYOUT_CACHE_KEY, 'failed');

        $refund = $this->approveThrough($this->submitRefund($player, 20000));

        $this->assertSame('payout_failed', $refund->status);
        $this->assertSame([30000, 0], $this->wallet($player));
        $this->assertSame(1, Anomaly::where('kind', 'payout_failed')->whereNull('resolved_at')->count());

        $this->service()->retryPayout($refund, $this->admin('supervisor'));

        $refund->refresh();
        $this->assertSame('paid_out', $refund->status);
        $this->assertSame([30000, 0], $this->wallet($player));
        $row = DB::table('mock_payouts')->first();
        $this->assertSame('succeeded', $row->status);
        $this->assertSame(2, (int) $row->attempts);
        $this->assertSame(1, DB::table('mock_payouts')->count());
        $this->assertSame(0, Anomaly::whereNull('resolved_at')->count());
        $this->assertLedgerConsistent();
    }

    public function test_payout_failure_can_be_closed_with_points_returned_once(): void
    {
        $player = $this->player('alice', 50000);
        Cache::put(MockGateway::NEXT_PAYOUT_CACHE_KEY, 'failed');
        $refund = $this->approveThrough($this->submitRefund($player, 20000));
        $supervisor = $this->admin('supervisor');

        $this->service()->closeWithReturn($refund, $supervisor, '帳戶錯誤');

        $this->assertSame('closed_points_returned', $refund->fresh()->status);
        $this->assertSame([50000, 0], $this->wallet($player));

        try {
            $this->service()->closeWithReturn($refund->fresh(), $supervisor, '再一次');
            $this->fail('cannot close twice');
        } catch (RuntimeException) {
        }
        $this->assertSame([50000, 0], $this->wallet($player));
        $this->assertLedgerConsistent();

        // 玩家可用正確帳戶重新申請。
        $this->assertSame('pending_review', $this->submitRefund($player, 20000)->status);
    }

    public function test_successful_payout_cannot_be_closed(): void
    {
        $player = $this->player('alice', 50000);
        $refund = $this->approveThrough($this->submitRefund($player, 20000));

        $this->expectException(RuntimeException::class);
        try {
            $this->service()->closeWithReturn($refund, $this->admin('supervisor'), '不該成功');
        } finally {
            $this->assertSame([30000, 0], $this->wallet($player));
        }
    }

    public function test_cannot_close_when_gateway_already_succeeded_even_if_status_says_failed(): void
    {
        $player = $this->player('alice', 50000);
        Cache::put(MockGateway::NEXT_PAYOUT_CACHE_KEY, 'failed');
        $refund = $this->approveThrough($this->submitRefund($player, 20000));
        DB::table('mock_payouts')->update(['status' => 'succeeded']);

        try {
            $this->service()->closeWithReturn($refund, $this->admin('supervisor'), '實際已撥款');
            $this->fail('should refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('已撥款成功', $e->getMessage());
        }
        $this->assertSame('payout_failed', $refund->fresh()->status);
        $this->assertSame([30000, 0], $this->wallet($player));
    }

    public function test_gateway_outage_exhausting_retries_marks_failed_and_retry_recovers(): void
    {
        $player = $this->player('alice', 50000);
        $refund = $this->submitRefund($player, 20000);
        $this->service()->reviewApprove($refund, $this->admin('reviewer'));

        // 不經過佇列，模擬玩家端扣點 API 故障。
        \Illuminate\Support\Facades\Queue::fake();
        $this->service()->supervisorApprove($refund->fresh(), $this->admin('supervisor'));
        $this->failingPaths = ['/api/game/players/*/deduct-frozen'];
        try {
            $this->service()->execute($refund->id);
            $this->fail('deduct should fail');
        } catch (\Illuminate\Http\Client\RequestException) {
        }
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertSame([50000, 20000], $this->wallet($player));

        $this->service()->markPayoutFailed($refund->id, 'outage');
        $this->assertSame('payout_failed', $refund->fresh()->status);

        // 還沒扣點就結案：只解凍，不會多還點數。
        $this->failingPaths = [];
        $this->service()->closeWithReturn($refund->fresh(), $this->admin('supervisor'), '服務異常');
        $this->assertSame([50000, 0], $this->wallet($player));
        $this->assertLedgerConsistent();
    }

    public function test_transient_error_leaves_request_retryable_and_never_double_pays(): void
    {
        $player = $this->player('alice', 50000);
        $refund = $this->submitRefund($player, 20000);
        $this->service()->reviewApprove($refund, $this->admin('reviewer'));
        \Illuminate\Support\Facades\Queue::fake();
        $this->service()->supervisorApprove($refund->fresh(), $this->admin('supervisor'));

        Cache::put(MockGateway::NEXT_PAYOUT_CACHE_KEY, 'error');
        try {
            $this->service()->execute($refund->id);
            $this->fail('transient error expected');
        } catch (RuntimeException) {
        }
        $this->assertSame('paying_out', $refund->fresh()->status);
        $this->assertSame([30000, 0], $this->wallet($player));

        $this->service()->execute($refund->id);
        $this->service()->execute($refund->id);

        $this->assertSame(1, DB::table('mock_payouts')->count());
        $this->assertSame([30000, 0], $this->wallet($player));
    }

    public function test_account_ciphertext_is_cleared_after_closing_and_never_stored_plain(): void
    {
        $player = $this->player('alice', 100000);
        Log::spy();

        // paid_out
        $refund = $this->submitRefund($player, 10000);
        $raw = DB::table('refund_requests')->where('id', $refund->id)->first();
        $this->assertStringNotContainsString('1234567890123', $raw->account_encrypted);
        $this->assertSame('0123', $raw->account_last4);
        $this->assertSame('1234567890123', $refund->account_encrypted);
        $this->assertSame('812-****0123', $refund->maskedAccount());

        $this->approveThrough($refund);
        $this->assertNull(DB::table('refund_requests')->where('id', $refund->id)->value('account_encrypted'));

        // rejected
        $rejected = $this->submitRefund($player, 10000);
        $this->service()->reviewReject($rejected, $this->admin('reviewer'), '拒絕');
        $this->assertNull(DB::table('refund_requests')->where('id', $rejected->id)->value('account_encrypted'));

        // closed_points_returned
        Cache::put(MockGateway::NEXT_PAYOUT_CACHE_KEY, 'failed');
        $closed = $this->approveThrough($this->submitRefund($player, 10000));
        $this->service()->closeWithReturn($closed, $this->admin('supervisor'), '結案');
        $this->assertNull(DB::table('refund_requests')->where('id', $closed->id)->value('account_encrypted'));

        // 稽核紀錄、事件與模擬金流都沒有完整帳號。
        foreach (['audit_logs', 'gateway_events', 'mock_payouts', 'anomalies'] as $table) {
            $this->assertStringNotContainsString('1234567890123', json_encode(DB::table($table)->get()), $table);
        }
    }

    public function test_player_view_hides_review_tier_and_admin_identity(): void
    {
        $player = $this->player('alice', 50000);
        $refund = $this->submitRefund($player, 20000);
        $this->service()->reviewApprove($refund, $this->admin('reviewer'));

        $html = $this->actingAs($player, 'game')->get(route('game.refunds'))->assertOk()->assertSee('審核中')->getContent();
        $this->assertStringNotContainsString('待覆核', $html);
        $this->assertStringNotContainsString('reviewer', $html);
        $this->assertStringContainsString('812-****0123', $html);
        $this->assertStringNotContainsString('1234567890123', $html);
    }

    public function test_player_web_requires_acknowledgement_and_has_no_withdraw_route(): void
    {
        $player = $this->player('alice', 50000);
        $form = ['request_id' => (string) Str::uuid(), 'points' => 10000, 'bank_account_id' => $this->bankAccount($player)->id];

        $this->actingAs($player, 'game')->post(route('game.refunds.submit'), $form + ['acknowledged' => ''])
            ->assertSessionHasErrors('acknowledged');
        $this->assertSame(0, RefundRequest::count());

        foreach (['points' => 9900, 'bank_account_id' => ''] as $field => $bad) {
            $this->actingAs($player, 'game')->post(route('game.refunds.submit'), array_merge($form, [$field => $bad, 'acknowledged' => '1']))
                ->assertSessionHasErrors($field);
        }
        $this->actingAs($player, 'game')->post(route('game.refunds.submit'), array_merge($form, ['points' => 60000, 'acknowledged' => '1']))
            ->assertSessionHasErrors('points');
        $this->assertSame(0, RefundRequest::count());

        $this->actingAs($player, 'game')->post(route('game.refunds.submit'), $form + ['acknowledged' => '1'])->assertRedirect();
        $this->assertSame(1, RefundRequest::count());

        // 玩家端與 API 都沒有撤銷路由。
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())->map(fn ($r) => $r->uri())->implode("\n");
        $this->assertStringNotContainsString('withdraw', $routes);
        $this->assertStringNotContainsString('cancel', $routes);
    }

    public function test_available_points_are_updated_right_after_submitting(): void
    {
        $player = $this->player('alice', 50000);
        $form = ['points' => 20000, 'bank_account_id' => $this->bankAccount($player)->id, 'acknowledged' => '1'];

        // 凍結完成後才跳轉，退款頁的可用點數已扣除。
        $this->actingAs($player, 'game')->post(route('game.refunds.submit'), $form + ['request_id' => (string) Str::uuid()])
            ->assertRedirect(route('game.refunds'))
            ->assertSessionHas('status', '退款申請已送出，已凍結 20,000 點');
        $this->actingAs($player->fresh(), 'game')->get(route('game.refunds'))
            ->assertSee('可用點數 30,000 點')->assertDontSee('正在凍結');

        // 背景工作還沒完成時不等太久，退款頁顯示凍結中並自動更新。
        config(['topup.refund_freeze_wait_ms' => 0]);
        \Illuminate\Support\Facades\Queue::fake();
        $bob = $this->player('bob', 50000);   // 一位玩家同時只能有一筆進行中的申請
        $this->actingAs($bob, 'game')->post(route('game.refunds.submit'), ['request_id' => (string) Str::uuid(), 'bank_account_id' => $this->bankAccount($bob)->id] + $form)
            ->assertSessionHasNoErrors()->assertSessionHas('status', '退款申請已送出，點數凍結處理中，頁面會自動更新');
        $this->actingAs($bob->fresh(), 'game')->get(route('game.refunds'))
            ->assertSee('有 20,000 點正在凍結')->assertSee('http-equiv="refresh"', false);
    }

    public function test_refund_list_shows_the_action_for_each_role(): void
    {
        $review = $this->submitRefund($this->player('alice', 50000));
        $second = $this->submitRefund($this->player('bob', 50000));
        $second->update(['status' => 'pending_supervisor']);
        $show = fn ($r) => 'href="'.route('admin.refunds.show', $r).'">';

        $html = $this->actingAs($this->admin('reviewer'), 'admin')->get(route('admin.refunds'))->assertOk()->getContent();
        $this->assertStringContainsString('<a class="button" '.$show($review).'初審</a>', $html);
        $this->assertStringContainsString('<a class="button secondary" '.$show($second).'查看</a>', $html);

        $html = $this->actingAs($this->admin('supervisor'), 'admin')->get(route('admin.refunds'))->getContent();
        $this->assertStringContainsString('<a class="button" '.$show($second).'覆核</a>', $html);
        $this->assertStringContainsString('<a class="button secondary" '.$show($review).'查看</a>', $html);
    }

    public function test_full_audit_trail_is_recorded(): void
    {
        $refund = $this->approveThrough($this->submitRefund($this->player('alice', 50000), 20000));

        $actions = AuditLog::where('subject_type', 'RefundRequest')->where('subject_id', $refund->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame([
            'refund.submitted', 'refund.frozen', 'refund.review_approved', 'refund.approved',
            'refund.points_deducted', 'refund.paying_out', 'refund.paid_out',
        ], $actions);
        $this->assertSame('supervisor', AuditLog::where('action', 'refund.approved')->value('actor'));
    }

    public function test_overdue_flag_appears_after_configured_hours_without_auto_decision(): void
    {
        $refund = $this->submitRefund($this->player('alice', 50000));
        $this->assertFalse($refund->isOverdue());

        $this->travel(73)->hours();
        $refund->refresh();

        $this->assertTrue($refund->isOverdue());
        $this->assertSame('pending_review', $refund->status);
        $this->actingAs($this->admin('reviewer'), 'admin')->get(route('admin.refunds'))->assertSee('審核逾時');
    }

    public function test_reconcile_uses_gateway_status_for_stuck_payouts(): void
    {
        $player = $this->player('alice', 50000);
        $refund = $this->submitRefund($player, 20000);
        $this->service()->reviewApprove($refund, $this->admin('reviewer'));
        \Illuminate\Support\Facades\Queue::fake();
        $this->service()->supervisorApprove($refund->fresh(), $this->admin('supervisor'));
        $this->service()->execute($refund->id); // 金流已成功，但 Webhook 工作被 fake 吞掉。
        $this->assertSame('paying_out', $refund->fresh()->status);

        $this->travel(11)->minutes();
        $this->service()->reconcile();

        $this->assertSame('paid_out', $refund->fresh()->status);
        $this->assertSame(1, DB::table('mock_payouts')->count());
    }

    public function test_game_player_model_is_unchanged_by_refund_flow(): void
    {
        $player = $this->player('alice', 50000);
        $this->approveThrough($this->submitRefund($player, 20000));
        $this->assertNull(GamePlayer::find($player->id)->suspended_at);
    }
}
