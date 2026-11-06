<?php

namespace Tests\Feature;

use App\DemoGame\Models\RedPacket;
use App\DemoGame\Exceptions\InsufficientPoints;
use App\DemoGame\Services\RedPacketService;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RedPacketTest extends TestCase
{
    private function service(): RedPacketService
    {
        return app(RedPacketService::class);
    }

    public function test_send_deducts_and_recipient_claims_once(): void
    {
        $alice = $this->player('alice', 1000);
        $bob = $this->player('bob');

        $packet = $this->service()->send($alice, $bob->player_no, 300, '恭喜', (string) Str::uuid());
        $this->assertSame([700, 0], $this->wallet($alice));
        $this->assertSame([0, 0], $this->wallet($bob));

        $this->service()->claim($bob, $packet->id);
        $this->assertSame([300, 0], $this->wallet($bob));

        try {
            $this->service()->claim($bob, $packet->id);
            $this->fail('second claim should fail');
        } catch (RuntimeException) {
        }
        $this->assertSame([300, 0], $this->wallet($bob));
        $this->assertLedgerConsistent();
    }

    public function test_only_recipient_can_claim(): void
    {
        $alice = $this->player('alice', 1000);
        $bob = $this->player('bob');
        $carol = $this->player('carol');
        $packet = $this->service()->send($alice, $bob->player_no, 300, null, (string) Str::uuid());

        foreach ([$carol, $alice] as $intruder) {
            try {
                $this->service()->claim($intruder, $packet->id);
                $this->fail('only the recipient can claim');
            } catch (RuntimeException) {
            }
        }
        $this->assertSame('sent', $packet->fresh()->status);
    }

    public function test_cannot_send_to_self_or_unknown_or_suspended(): void
    {
        $alice = $this->player('alice', 1000);
        $bob = $this->player('bob');
        $bob->update(['suspended_at' => now()]);

        foreach ([$alice->player_no, 'PNOPE', $bob->player_no] as $target) {
            try {
                $this->service()->send($alice, $target, 1, null, (string) Str::uuid());
                $this->fail("should reject $target");
            } catch (RuntimeException) {
            }
        }
        $this->assertSame([1000, 0], $this->wallet($alice));
    }

    public function test_insufficient_balance_and_frozen_points_cannot_be_sent(): void
    {
        $alice = $this->player('alice', 20000);
        $bob = $this->player('bob');
        $this->submitRefund($alice, 15000);
        $this->assertSame([20000, 15000], $this->wallet($alice));

        try {
            $this->service()->send($alice, $bob->player_no, 5001, null, (string) Str::uuid());
            $this->fail('frozen points must not be sendable');
        } catch (InsufficientPoints) {
        }

        $this->service()->send($alice, $bob->player_no, 5000, null, (string) Str::uuid());
        $this->assertSame([15000, 15000], $this->wallet($alice));
    }

    public function test_resubmitting_same_form_only_sends_once(): void
    {
        $alice = $this->player('alice', 1000);
        $bob = $this->player('bob');
        $requestId = (string) Str::uuid();

        $first = $this->service()->send($alice, $bob->player_no, 100, null, $requestId);
        $second = $this->service()->send($alice, $bob->player_no, 100, null, $requestId);

        $this->assertSame($first->id, $second->id);
        $this->assertSame([900, 0], $this->wallet($alice));
        $this->assertSame(1, RedPacket::count());
    }

    public function test_expired_packet_returns_to_sender_and_cannot_be_claimed(): void
    {
        $alice = $this->player('alice', 1000);
        $bob = $this->player('bob');
        $packet = $this->service()->send($alice, $bob->player_no, 300, null, (string) Str::uuid());

        $this->travel(24)->hours();
        $this->travel(1)->seconds();

        try {
            $this->service()->claim($bob, $packet->id);
            $this->fail('expired packet cannot be claimed');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $this->service()->expireDue());
        $this->assertSame(0, $this->service()->expireDue());
        $this->assertSame('expired', $packet->fresh()->status);
        $this->assertSame([1000, 0], $this->wallet($alice));
        $this->assertSame([0, 0], $this->wallet($bob));
        $this->assertLedgerConsistent();
    }

    public function test_claimed_packet_is_not_returned_by_expiry(): void
    {
        $alice = $this->player('alice', 1000);
        $bob = $this->player('bob');
        $packet = $this->service()->send($alice, $bob->player_no, 300, null, (string) Str::uuid());
        $this->service()->claim($bob, $packet->id);

        $this->travel(25)->hours();
        $this->assertSame(0, $this->service()->expireDue());

        $this->assertSame([700, 0], $this->wallet($alice));
        $this->assertSame([300, 0], $this->wallet($bob));
    }

    public function test_received_points_can_be_refunded(): void
    {
        $alice = $this->player('alice', 10000);
        $bob = $this->player('bob');
        $packet = $this->service()->send($alice, $bob->player_no, 10000, null, (string) Str::uuid());
        $this->service()->claim($bob, $packet->id);

        $refund = $this->submitRefund($bob, 10000);

        $this->assertSame('pending_review', $refund->status);
        $this->assertSame(100, $refund->amount);
    }

    public function test_web_flow_shows_confirmation_before_sending(): void
    {
        $alice = $this->player('alice', 1000);
        $bob = $this->player('bob');

        $this->actingAs($alice, 'game')
            ->post(route('game.red-packets.confirm'), ['player_no' => $bob->player_no, 'points' => 100, 'message' => '<b>hi</b>'])
            ->assertOk()->assertSee($bob->player_no)->assertSee($bob->nickname)->assertSee('&lt;b&gt;hi&lt;/b&gt;', false);
        $this->assertSame([1000, 0], $this->wallet($alice));

        $this->post(route('game.red-packets.send'), ['player_no' => $bob->player_no, 'points' => 100, 'request_id' => (string) Str::uuid()])
            ->assertRedirect(route('game.red-packets'));
        $this->assertSame([900, 0], $this->wallet($alice));
    }
}
