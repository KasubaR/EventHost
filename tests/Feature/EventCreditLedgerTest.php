<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Admin;
use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Services\EventCreditService;
use App\Services\PaymentCompletionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventCreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function credits(): EventCreditService
    {
        return app(EventCreditService::class);
    }

    public function test_a_grant_writes_a_row_and_raises_the_balance(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $entry = $this->credits()->grant($user, 3, CreditTransaction::REASON_ADMIN_GRANT);

        $this->assertSame(3, $entry->delta);
        $this->assertSame(3, $entry->balance_after);
        $this->assertSame(3, $user->fresh()->event_credits);
    }

    public function test_a_spend_writes_a_negative_row_linked_to_the_event(): void
    {
        $user = User::factory()->withCredits(2)->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $entry = $this->credits()->spend($user, CreditTransaction::REASON_EVENT_CREATED, $event);

        $this->assertSame(-1, $entry->delta);
        $this->assertSame(1, $entry->balance_after);
        $this->assertSame($event->id, $entry->event_id);
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_spending_an_empty_balance_throws_and_writes_nothing(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->expectException(InsufficientCreditsException::class);

        try {
            $this->credits()->spend($user, CreditTransaction::REASON_EVENT_CREATED);
        } finally {
            $this->assertDatabaseCount('credit_transactions', 0);
            $this->assertSame(0, $user->fresh()->event_credits);
        }
    }

    public function test_balance_after_tracks_a_running_sequence(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->credits()->grant($user, 2, CreditTransaction::REASON_ADMIN_GRANT);
        $this->credits()->spend($user, CreditTransaction::REASON_EVENT_CREATED);
        $this->credits()->grant($user, 1, CreditTransaction::REASON_PURCHASE);

        $balances = CreditTransaction::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->pluck('balance_after')
            ->all();

        $this->assertSame([2, 1, 2], $balances);
        $this->assertSame(2, $user->fresh()->event_credits);
    }

    public function test_payment_fulfilment_records_a_purchase_linked_to_the_payment(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'credits_granted' => 2,
            'notified_at' => null,
        ]);

        app(PaymentCompletionService::class)->complete($payment);

        $entry = CreditTransaction::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(CreditTransaction::REASON_PURCHASE, $entry->reason);
        $this->assertSame(2, $entry->delta);
        $this->assertSame($payment->id, $entry->payment_id);
        $this->assertSame(2, $user->fresh()->event_credits);
    }

    public function test_refulfilling_a_payment_writes_no_second_row(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'credits_granted' => 1,
            'notified_at' => null,
        ]);

        app(PaymentCompletionService::class)->complete($payment);
        app(PaymentCompletionService::class)->complete($payment->fresh());

        $this->assertSame(1, CreditTransaction::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_publishing_an_event_records_event_published(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->for($user)->create(['is_published' => false]);

        $this->actingAs($user)->patch(route('events.publish', $event));

        $entry = CreditTransaction::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(CreditTransaction::REASON_EVENT_PUBLISHED, $entry->reason);
        $this->assertSame(-1, $entry->delta);
        $this->assertSame($event->id, $entry->event_id);
    }

    public function test_creating_a_draft_writes_no_ledger_row(): void
    {
        $user = User::factory()->withCredits(1)->create();

        $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Chanda & Mwila Wedding',
            'event_type' => 'wedding',
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'event_time' => '14:00',
        ]);

        $this->assertSame(0, CreditTransaction::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_redefining_a_past_event_records_event_redefined(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->published()->create([
            'user_id' => $user->id,
            'event_date' => now()->subWeek()->format('Y-m-d'),
        ]);

        $this->actingAs($user)->patch(route('events.update', $event), [
            'name' => 'A Different Event',
            'event_type' => 'corporate',
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'event_time' => '10:00',
        ]);

        $entry = CreditTransaction::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(CreditTransaction::REASON_EVENT_REDEFINED, $entry->reason);
        $this->assertSame($event->id, $entry->event_id);
        $this->assertSame(0, $entry->balance_after);
    }

    public function test_an_admin_grant_is_recorded_from_the_admin_panel(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        $user = User::factory()->withoutCredits()->create();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.add-credits', $user), ['credits' => 5]);

        $entry = CreditTransaction::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(CreditTransaction::REASON_ADMIN_GRANT, $entry->reason);
        $this->assertSame(5, $entry->delta);
        $this->assertSame(5, $user->fresh()->event_credits);
    }

    public function test_the_admin_user_page_shows_the_credit_history(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        $user = User::factory()->withoutCredits()->create();
        $this->credits()->grant($user, 2, CreditTransaction::REASON_ADMIN_GRANT);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('Credit history', escape: false)
            ->assertSee('Admin grant', escape: false);
    }

    public function test_an_unknown_reason_is_refused(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->credits()->grant($user, 1, 'not-a-real-reason');
    }

    public function test_reversing_a_purchase_writes_a_refund_row(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->completed()->create([
            'credits_granted' => 1,
            'credits_fulfilled_at' => now(),
        ]);

        $this->credits()->grant($user, 1, CreditTransaction::REASON_PURCHASE, $payment);
        $entry = $this->credits()->reversePurchase($user, $payment);

        $this->assertSame(CreditTransaction::REASON_REFUND, $entry->reason);
        $this->assertSame(-1, $entry->delta);
        $this->assertSame($payment->id, $entry->payment_id);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_saving_event_credits_on_the_user_is_refused(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->expectException(\RuntimeException::class);

        $user->event_credits = 9;
        $user->save();
    }

    public function test_credits_audit_reports_a_mismatched_balance(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $this->credits()->grant($user, 2, CreditTransaction::REASON_ADMIN_GRANT);
        User::query()->whereKey($user->id)->update(['event_credits' => 9]);

        $this->artisan('credits:audit')->assertFailed();
    }
}
