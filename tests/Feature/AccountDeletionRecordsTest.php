<?php

namespace Tests\Feature;

use App\Enums\TicketOrderStatus;
use App\Models\Admin;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\Payment;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\PaymentCompletionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * plans/event-retention.md §6b — the account-deletion guard uses the same "has this event
 * taken money" rule as the purge, and the user's own payments survive the account.
 */
class AccountDeletionRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function deleteAccount(User $user): TestResponse
    {
        return $this->actingAs($user)
            ->from('/settings/account')
            ->delete('/settings/account', ['password' => 'password']);
    }

    private function assertBlocked(User $user, ?string $message = null): void
    {
        $this->deleteAccount($user)
            ->assertSessionHasErrorsIn('userDeletion', ['blocked'])
            ->assertRedirect('/settings/account');

        $this->assertNotNull($user->fresh(), 'the account must survive a blocked deletion');

        if ($message !== null) {
            $this->assertStringContainsString($message, session()->get('errors')->getBag('userDeletion')->first('blocked'));
        }
    }

    public function contributionPayment(string $status): array
    {
        return [
            'payment_method' => 'mobile_money',
            'amount' => '50.00',
            'currency' => 'ZMW',
            'status' => $status,
            'payment_reference' => 'CTBP-'.uniqid(),
        ];
    }

    // ── The guard: the same definition as the purge ───────────────────────────────────

    public function test_a_refunded_ticket_order_blocks_account_deletion(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketOrder::factory()->for($event)->create(['status' => TicketOrderStatus::Refunded]);

        $this->assertBlocked($user);
    }

    public function test_an_in_flight_ticket_order_blocks_account_deletion(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketOrder::factory()->for($event)->create(['status' => TicketOrderStatus::inFlight()[0]]);

        $this->assertBlocked($user);
    }

    public function test_a_refunded_order_on_a_deleted_event_still_blocks(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketOrder::factory()->for($event)->create(['status' => TicketOrderStatus::Refunded]);
        $event->delete();

        $this->assertBlocked($user);
    }

    /** @return array<string, array{0: callable}> */
    public static function contributionsThatTookMoney(): array
    {
        return [
            'completed payment' => [fn (EventContribution $c, self $t) => $c->payments()->create($t->contributionPayment('completed'))],
            'refunded payment' => [fn (EventContribution $c, self $t) => $c->payments()->create($t->contributionPayment('refunded'))],
            'amount paid' => [fn (EventContribution $c) => $c->forceFill(['amount_paid' => '40.00'])->save()],
            'recent pending payment' => [fn (EventContribution $c, self $t) => $c->payments()->create($t->contributionPayment('pending'))],
        ];
    }

    #[DataProvider('contributionsThatTookMoney')]
    public function test_contribution_money_blocks_account_deletion(callable $arrange): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $arrange(EventContribution::factory()->for($event)->create(), $this);

        $this->assertBlocked($user);
    }

    public function test_contribution_money_on_a_deleted_event_still_blocks(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        EventContribution::factory()->for($event)->create()->payments()->create($this->contributionPayment('completed'));
        $event->delete();

        $this->assertBlocked($user);
    }

    public function test_an_abandoned_or_failed_contribution_does_not_block(): void
    {
        $user = User::factory()->create();

        $abandoned = Event::factory()->for($user)->create();
        $stale = EventContribution::factory()->for($abandoned)->create()->payments()->create($this->contributionPayment('pending'));
        $stale->forceFill(['created_at' => now()->subDays(Event::CONTRIBUTION_IN_FLIGHT_DAYS + 1)])->save();

        $failed = Event::factory()->for($user)->create();
        EventContribution::factory()->for($failed)->create()->payments()->create($this->contributionPayment('failed'));

        $this->deleteAccount($user)->assertRedirect('/');

        $this->assertNull($user->fresh());
    }

    public function test_another_hosts_money_does_not_block_this_account(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->create();

        $other = Event::factory()->for(User::factory())->ticketed()->create();
        TicketOrder::factory()->for($other)->paid()->create();

        $this->deleteAccount($user)->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertNotNull(Event::query()->find($other->id));
    }

    public function test_the_blocked_message_names_recently_deleted_and_tells_the_user_what_to_do(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketOrder::factory()->for($event)->create(['status' => TicketOrderStatus::Refunded]);

        $this->assertBlocked($user, 'Recently deleted');
        $this->assertStringContainsString('Contact support', AccountDeletionService::messageFor(AccountDeletionService::BLOCKED_BY_EVENTS));
    }

    public function test_the_api_applies_the_same_widened_guard(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123')]);
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketOrder::factory()->for($event)->create(['status' => TicketOrderStatus::Refunded]);

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->deleteJson(route('api.v1.settings.account.destroy'), ['password' => 'Password123'])
            ->assertStatus(409)
            ->assertJsonStructure(['message']);

        $this->assertNotNull($user->fresh());
        $this->assertNotNull(Event::query()->find($event->id));
    }

    // ── A payment of the user's own that is still settling ───────────────────────────

    public function test_a_payment_that_can_still_settle_blocks_account_deletion(): void
    {
        foreach (['pending', 'processing'] as $status) {
            $user = User::factory()->withoutCredits()->create();
            Payment::factory()->for($user)->create(['status' => $status]);

            $this->assertBlocked($user, 'still being processed');
        }
    }

    public function test_a_stale_pending_payment_does_not_block(): void
    {
        $user = User::factory()->withoutCredits()->create();
        Payment::factory()->for($user)->create([
            'status' => 'pending',
            'created_at' => now()->subHours(Payment::IN_FLIGHT_HOURS + 1),
        ]);

        $this->deleteAccount($user)->assertRedirect('/');

        $this->assertNull($user->fresh());
    }

    // ── The user's own payment history survives ──────────────────────────────────────

    public function test_money_payments_survive_the_account_with_a_payer_snapshot(): void
    {
        $user = User::factory()->withoutCredits()->create(['name' => 'Mwansa Banda', 'email' => 'mwansa@example.test']);
        $completed = Payment::factory()->for($user)->completed()->create(['payment_reference' => 'EH-kept-1']);
        $refunded = Payment::factory()->for($user)->create(['status' => 'refunded', 'payment_reference' => 'EH-kept-2']);
        $processing = Payment::factory()->for($user)->create([
            'status' => 'processing',
            'payment_reference' => 'EH-kept-3',
            'created_at' => now()->subDays(3),
        ]);

        $this->deleteAccount($user)->assertRedirect('/');

        $this->assertNull($user->fresh());

        foreach ([$completed, $refunded, $processing] as $payment) {
            $kept = Payment::query()->find($payment->id);
            $this->assertNotNull($kept, 'a payment that moved money must survive the account');
            $this->assertNull($kept->user_id);
            $this->assertSame('Mwansa Banda', $kept->payer_name);
            $this->assertSame('mwansa@example.test', $kept->payer_email);
        }

        $this->assertSame('450.00', (string) Payment::query()->find($completed->id)->amount);
    }

    public function test_payments_that_never_moved_money_go_with_the_account(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $old = now()->subDays(3);
        $failed = Payment::factory()->for($user)->create(['status' => 'failed']);
        $cancelled = Payment::factory()->for($user)->create(['status' => 'cancelled']);
        $abandoned = Payment::factory()->for($user)->create(['status' => 'pending', 'created_at' => $old]);

        $this->deleteAccount($user)->assertRedirect('/');

        foreach ([$failed, $cancelled, $abandoned] as $payment) {
            $this->assertNull(Payment::query()->find($payment->id));
        }
    }

    public function test_other_users_payments_are_untouched(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $other = User::factory()->withoutCredits()->create();
        $theirs = Payment::factory()->for($other)->completed()->create();
        $theirFailed = Payment::factory()->for($other)->create(['status' => 'failed']);

        $this->deleteAccount($user)->assertRedirect('/');

        $this->assertSame($other->id, Payment::query()->find($theirs->id)->user_id);
        $this->assertNull(Payment::query()->find($theirs->id)->payer_email);
        $this->assertNotNull(Payment::query()->find($theirFailed->id));
    }

    public function test_a_blocked_deletion_changes_nothing(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketOrder::factory()->for($event)->paid()->create();
        $keptIfDeleted = Payment::factory()->for($user)->completed()->create();
        $droppedIfDeleted = Payment::factory()->for($user)->create(['status' => 'failed']);

        $this->assertBlocked($user);

        $this->assertSame($user->id, $keptIfDeleted->fresh()->user_id);
        $this->assertNull($keptIfDeleted->fresh()->payer_email, 'nothing may be snapshotted for an account that stays');
        $this->assertNotNull($droppedIfDeleted->fresh(), 'nothing may be deleted for an account that stays');
    }

    public function test_the_api_keeps_payments_too(): void
    {
        $user = User::factory()->withoutCredits()->create(['password' => Hash::make('Password123'), 'email' => 'api@example.test']);
        $payment = Payment::factory()->for($user)->completed()->create();

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->deleteJson(route('api.v1.settings.account.destroy'), ['password' => 'Password123'])
            ->assertOk();

        $this->assertNull($user->fresh());
        $this->assertNull(Payment::query()->find($payment->id)->user_id);
        $this->assertSame('api@example.test', Payment::query()->find($payment->id)->payer_email);
    }

    // ── A kept payment keeps working ─────────────────────────────────────────────────

    public function test_a_kept_payment_can_still_be_refunded_by_the_provider(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->completed()->create([
            'credits_fulfilled_at' => now(),
            'credits_granted' => 1,
            'notified_at' => now(),
        ]);
        $this->deleteAccount($user);

        $result = app(PaymentCompletionService::class)->reverse($payment->fresh(), 'refunded');

        $this->assertSame('refunded', $result->status);
        $this->assertNotNull($result->credits_reversed_at);
        $this->assertNull($result->user_id);
    }

    public function test_a_late_completion_for_a_deleted_account_is_recorded_and_skipped(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->create(['status' => 'processing', 'created_at' => now()->subDays(2)]);
        $this->deleteAccount($user);

        $payment = Payment::query()->findOrFail($payment->id);
        $payment->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        app(PaymentCompletionService::class)->complete($payment);

        $payment->refresh();
        $this->assertSame('completed', $payment->status);
        $this->assertNull($payment->credits_fulfilled_at, 'there is no account to credit');
        $this->assertNull($payment->notified_at);
    }

    // ── What admins see ───────────────────────────────────────────────────────────────

    public function test_admin_sees_who_paid_for_a_payment_whose_account_is_gone(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        $user = User::factory()->withoutCredits()->create(['name' => 'Chanda Phiri', 'email' => 'chanda@example.test']);
        $payment = Payment::factory()->for($user)->completed()->create(['payment_reference' => 'EH-gone-user']);
        $this->deleteAccount($user);
        auth()->logout();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee('Chanda Phiri')
            ->assertSee('deleted account');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.show', $payment))
            ->assertOk()
            ->assertSee('chanda@example.test')
            ->assertSee('details kept for accounting');
    }
}
