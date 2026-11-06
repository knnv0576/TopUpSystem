<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Player;
use App\Models\PlayerBankAccount;
use App\Models\RefundRequest;
use App\Services\RefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankAccountTest extends TestCase
{
    private function addForm(array $overrides = []): array
    {
        return $overrides + ['label' => '薪轉帳戶', 'bank_code' => '812', 'bank_account' => '1234567890123', 'holder_name' => '王小明', 'password' => 'Password123456'];
    }

    public function test_player_binds_accounts_with_password_and_sees_only_masked_numbers(): void
    {
        $player = $this->player('alice');

        $this->actingAs($player, 'game')->post(route('game.refunds.accounts.store'), $this->addForm(['password' => 'wrong-password']))
            ->assertSessionHasErrors(['password' => '密碼不正確']);
        $this->assertSame(0, PlayerBankAccount::count());

        $this->actingAs($player, 'game')->post(route('game.refunds.accounts.store'), $this->addForm())
            ->assertRedirect(route('game.refunds'))->assertSessionHas('status', '已綁定撥款帳戶 812-****0123（薪轉帳戶）');
        $this->actingAs($player, 'game')->post(route('game.refunds.accounts.store'), $this->addForm(['label' => '郵局', 'bank_code' => '700', 'bank_account' => '00012345678901']));
        $this->assertSame(2, PlayerBankAccount::count());

        // 帳號只存密文；密碼與帳號不會留在 session。
        $raw = DB::table('player_bank_accounts')->where('bank_code', '812')->first();
        $this->assertStringNotContainsString('1234567890123', $raw->account_encrypted);
        $this->assertSame('1234567890123', PlayerBankAccount::where('bank_code', '812')->first()->account_encrypted);
        $this->assertArrayNotHasKey('bank_account', session()->getOldInput());

        $html = $this->actingAs($player, 'game')->get(route('game.refunds'))->assertOk()->getContent();
        $this->assertStringContainsString('812-****0123', $html);
        $this->assertStringContainsString('700-****8901', $html);
        $this->assertStringNotContainsString('1234567890123', $html);
        $this->assertSame(2, AuditLog::where('action', 'bank_account.added')->count());
        $this->assertStringNotContainsString('1234567890123', json_encode(AuditLog::all()));
    }

    public function test_duplicates_and_limit_are_rejected(): void
    {
        $player = $this->player('alice');
        $this->bankAccount($player, '812', '1234567890');

        $this->actingAs($player, 'game')->post(route('game.refunds.accounts.store'), $this->addForm(['bank_account' => '1234567890']))
            ->assertSessionHasErrors(['bank_account' => '這個帳戶已經綁定過了']);

        for ($i = 2; $i <= PlayerBankAccount::MAX_PER_PLAYER; $i++) {
            $this->bankAccount($player, '812', "99999999{$i}");
        }
        $this->actingAs($player, 'game')->post(route('game.refunds.accounts.store'), $this->addForm(['bank_account' => '5555555555']))
            ->assertSessionHasErrors(['bank_account' => '最多只能綁定 5 個撥款帳戶']);
        $this->assertSame(PlayerBankAccount::MAX_PER_PLAYER, PlayerBankAccount::count());

        // 同一個帳戶可以由不同玩家各自綁定。
        $this->bankAccount($this->player('bob'), '812', '1234567890');
        $this->assertSame(PlayerBankAccount::MAX_PER_PLAYER + 1, PlayerBankAccount::count());
    }

    public function test_refund_pays_out_to_the_selected_account(): void
    {
        $player = $this->player('alice', 50000);
        $this->bankAccount($player, '812', '1111111111', '第一個');
        $second = $this->bankAccount($player, '700', '22222222222222', '第二個');

        $this->actingAs($player, 'game')->post(route('game.refunds.submit'), [
            'request_id' => (string) Str::uuid(), 'points' => 10000, 'bank_account_id' => $second->id, 'acknowledged' => '1',
        ])->assertSessionHasNoErrors();

        $refund = RefundRequest::sole();
        $this->assertSame(['700', '2222', '22222222222222', $second->id, '第二個'], [$refund->bank_code, $refund->account_last4, $refund->account_encrypted, $refund->bank_account_id, $refund->bank_label]);
        $this->actingAs($player, 'game')->get(route('game.refunds'))->assertSee('700-****2222')->assertSee('第二個');

        // 審核中刪除綁定：申請保留帳戶快照，撥款仍到原本選的帳戶。
        $this->actingAs($player, 'game')->post(route('game.refunds.accounts.destroy', $second->id))->assertSessionHas('status', '已刪除撥款帳戶');
        $this->assertNull(PlayerBankAccount::find($second->id));
        $refund = $refund->fresh();
        $this->assertNull($refund->bank_account_id);
        $this->assertSame(['700', '22222222222222', '第二個'], [$refund->bank_code, $refund->account_encrypted, $refund->bank_label]);

        $service = app(RefundService::class);
        $reviewer = $this->admin('reviewer');
        $supervisor = $this->admin('supervisor');
        $service->reviewApprove($refund, $reviewer);
        $service->supervisorApprove($refund->fresh(), $supervisor);

        $this->assertSame('paid_out', $refund->fresh()->status);
        $this->assertSame(100, (int) DB::table('mock_payouts')->value('amount'));
        $this->assertLedgerConsistent();
    }

    public function test_refund_cannot_use_someone_elses_account_or_a_raw_account_number(): void
    {
        $alice = $this->player('alice', 50000);
        $bobAccount = $this->bankAccount($this->player('bob'));

        $this->actingAs($alice, 'game')->post(route('game.refunds.submit'), [
            'request_id' => (string) Str::uuid(), 'points' => 10000, 'bank_account_id' => $bobAccount->id, 'acknowledged' => '1',
        ])->assertSessionHasErrors(['points' => '找不到這個撥款帳戶，請重新選擇']);

        // 舊的欄位（直接填帳號）已不接受。
        $this->actingAs($alice, 'game')->post(route('game.refunds.submit'), [
            'request_id' => (string) Str::uuid(), 'points' => 10000, 'bank_code' => '812', 'bank_account' => '1234567890', 'acknowledged' => '1',
        ])->assertSessionHasErrors(['bank_account_id' => '請選擇撥款帳戶']);
        $this->assertSame(0, RefundRequest::count());

        // 也不能刪除別人的帳戶。
        $this->actingAs($alice, 'game')->post(route('game.refunds.accounts.destroy', $bobAccount->id))
            ->assertSessionHasErrors(['bank_account' => '找不到這個撥款帳戶']);
        $this->assertNotNull($bobAccount->fresh());
    }

    public function test_binding_verifies_account_and_holder_name_with_the_bank(): void
    {
        $player = $this->player('alice');

        $this->actingAs($player, 'game')->post(route('game.refunds.accounts.store'), $this->addForm(['bank_account' => '1234569999']))
            ->assertSessionHasErrors(['bank_account' => '銀行查無這個帳號，請確認銀行代碼與帳號']);
        $this->actingAs($player, 'game')->post(route('game.refunds.accounts.store'), $this->addForm(['bank_account' => '1234568888']))
            ->assertSessionHasErrors(['bank_account' => '戶名與銀行資料不符，請確認戶名']);
        $this->actingAs($player, 'game')->post(route('game.refunds.accounts.store'), $this->addForm(['holder_name' => '1']))
            ->assertSessionHasErrors('holder_name');
        $this->assertSame(0, PlayerBankAccount::count());

        $this->actingAs($player, 'game')->post(route('game.refunds.accounts.store'), $this->addForm(['holder_name' => '王大明']))->assertSessionHasNoErrors();
        $account = PlayerBankAccount::sole();
        $this->assertSame(['王*明', '王大明'], [$account->holder_name_masked, $account->holder_name_encrypted]);
        $this->assertNotNull($account->verified_at);
        $this->assertStringNotContainsString('王大明', (string) DB::table('player_bank_accounts')->value('holder_name_encrypted'));
        $this->actingAs($player, 'game')->get(route('game.refunds'))->assertSee('王*明')->assertSee('戶名已驗證')->assertDontSee('王大明');

        $this->assertSame(['*', '王*', 'T*******R'], [PlayerBankAccount::maskName('王'), PlayerBankAccount::maskName('王明'), PlayerBankAccount::maskName('TEST USER')]);
    }

    public function test_returned_payout_waits_for_close_and_blocks_the_account(): void
    {
        $player = $this->player('alice', 50000);
        $account = $this->bankAccount($player, '812', '1234567777', '會被退匯');

        $this->actingAs($player, 'game')->post(route('game.refunds.submit'), [
            'request_id' => (string) Str::uuid(), 'points' => 10000, 'bank_account_id' => $account->id, 'acknowledged' => '1',
        ])->assertSessionHasNoErrors();
        $refund = RefundRequest::sole();
        $this->assertSame('王*明', $refund->holder_name_masked);

        $service = app(RefundService::class);
        $service->reviewApprove($refund, $this->admin('reviewer'));
        $supervisor = $this->admin('supervisor');
        $service->supervisorApprove($refund->fresh(), $supervisor);

        // 撥款成功之後又收到退匯通知。
        $refund = $refund->fresh();
        $this->assertSame('payout_returned', $refund->status);
        $this->assertSame('returned', $refund->payout->status);
        $this->assertSame('returned', DB::table('mock_payouts')->value('status'));
        $this->assertNotNull($account->fresh()->returned_at);
        $this->assertSame(1, \App\Models\Anomaly::where('kind', 'payout_returned')->whereNull('resolved_at')->count());
        $this->assertSame([40000, 0], $this->wallet($player));   // 點數已扣，等結案才還

        // 玩家看到處理中，退匯的帳戶不能再選；也不能再開新的申請。
        $this->actingAs($player, 'game')->get(route('game.refunds'))->assertSee('撥款被銀行退回，處理中')->assertSee('曾被退匯');
        $this->actingAs($player, 'game')->post(route('game.refunds.submit'), [
            'request_id' => (string) Str::uuid(), 'points' => 10000, 'bank_account_id' => $account->id, 'acknowledged' => '1',
        ])->assertSessionHasErrors(['points' => '這個帳戶曾被銀行退匯，請刪除後重新綁定正確的帳戶']);

        // 後台：列表顯示「處理退匯」，詳細頁沒有重試，只能結案還點。
        $this->actingAs($supervisor, 'admin')->get(route('admin.refunds'))->assertSee('處理退匯');
        $this->actingAs($supervisor, 'admin')->get(route('admin.refunds.show', $refund))->assertSee('退匯處理')->assertDontSee('重試撥款');

        $service->closeWithReturn($refund, $supervisor, '銀行退匯，請改綁正確帳戶後重新申請');
        $this->assertSame('closed_points_returned', $refund->fresh()->status);
        $this->assertSame([50000, 0], $this->wallet($player));
        $this->assertSame(0, \App\Models\Anomaly::where('kind', 'payout_returned')->whereNull('resolved_at')->count());
        $this->assertLedgerConsistent();
    }

    public function test_page_without_accounts_asks_to_bind_first(): void
    {
        $this->actingAs($this->player('alice', 50000), 'game')->get(route('game.refunds'))
            ->assertOk()->assertSee('還沒有綁定撥款帳戶，請先在下方「撥款帳戶」新增')
            ->assertSee('<button type="submit" disabled>送出申請</button>', false);
    }

    public function test_suspended_player_cannot_bind(): void
    {
        $player = $this->player('alice');
        Player::forGame($player->id)->update(['suspended_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->bankAccount($player);
    }
}
