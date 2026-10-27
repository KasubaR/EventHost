<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Models\Admin;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\TicketPayout;
use App\Models\User;
use App\Notifications\TicketPayoutRecordedNotification;
use App\Services\TicketRevenueLedgerService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Marking a payout as made: full or part, an optional private proof of payment, the host email, and the
 * derived status. Plan: plans/payout-marking.md.
 */
class AdminPayoutMarkingTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->event = Event::factory()->ticketed()->for(User::factory()->create())->create([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ]);
        $order = TicketOrder::factory()->for($this->event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        app(TicketRevenueLedgerService::class)->recordSale($order);

        $this->admin = Admin::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function pay(array $data)
    {
        return $this->actingAs($this->admin, 'admin')
            ->post(route('admin.ticketing.revenue.payouts.store', $this->event), $data + ['paid_on' => now()->toDateString()]);
    }

    public function test_a_part_payment_leaves_the_rest_pending_and_tells_the_host(): void
    {
        Notification::fake();

        $this->pay(['payment_type' => 'part', 'amount' => '100.00'])
            ->assertRedirect(route('admin.ticketing.revenue.show', $this->event))
            ->assertSessionHas('payout_remaining', 90.0);

        $this->assertSame('partial', app(TicketRevenueLedgerService::class)->payoutStateFor($this->event));
        Notification::assertSentTo(
            $this->event->user,
            TicketPayoutRecordedNotification::class,
            fn ($n) => $n->remaining === 90.0,
        );
    }

    public function test_paying_in_full_settles_the_exact_balance_whatever_amount_is_posted(): void
    {
        $this->pay(['payment_type' => 'full', 'amount' => '1.00'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_payouts', ['event_id' => $this->event->id, 'amount' => '190.00']);
        $this->assertSame('paid', app(TicketRevenueLedgerService::class)->payoutStateFor($this->event));
    }

    public function test_a_part_payment_needs_an_amount_and_cannot_exceed_the_balance(): void
    {
        $this->pay(['payment_type' => 'part'])->assertSessionHasErrors('amount');
        $this->pay(['payment_type' => 'part', 'amount' => '999.00'])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('ticket_payouts', 0);
        $this->assertSame('none', app(TicketRevenueLedgerService::class)->payoutStateFor($this->event));
    }

    public function test_proof_is_optional_stored_privately_and_viewable_by_admin_and_host_only(): void
    {
        $this->pay([
            'payment_type' => 'part', 'amount' => '50.00',
            'proof' => UploadedFile::fake()->image('receipt.png', 400, 400),
        ])->assertSessionHasNoErrors();

        $payout = TicketPayout::query()->where('event_id', $this->event->id)->firstOrFail();
        $this->assertTrue($payout->hasProof());
        Storage::disk('local')->assertExists($payout->proof_path);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.ticketing.revenue.payouts.proof', [$this->event, $payout]))
            ->assertOk();

        $this->actingAs($this->event->user)
            ->get(route('public-events.tickets.payouts.proof', [$this->event, $payout]))
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('public-events.tickets.payouts.proof', [$this->event, $payout]))
            ->assertForbidden();
    }

    public function test_a_payout_without_proof_is_fine(): void
    {
        $this->pay(['payment_type' => 'full'])->assertSessionHasNoErrors();

        $this->assertFalse(TicketPayout::query()->firstOrFail()->hasProof());
    }

    public function test_proof_must_be_an_image_or_pdf_within_the_size_limit(): void
    {
        $this->pay(['payment_type' => 'part', 'amount' => '10.00', 'proof' => UploadedFile::fake()->create('x.exe', 10)])
            ->assertSessionHasErrors('proof');
        $this->pay(['payment_type' => 'part', 'amount' => '10.00', 'proof' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')])
            ->assertSessionHasErrors('proof');

        $this->assertDatabaseCount('ticket_payouts', 0);
    }

    public function test_a_rejected_payout_leaves_no_proof_file_behind(): void
    {
        $this->pay([
            'payment_type' => 'part', 'amount' => '999.00',
            'proof' => UploadedFile::fake()->image('receipt.png', 400, 400),
        ])->assertSessionHasErrors('amount');

        $this->assertSame([], Storage::disk('local')->allFiles('payout-proofs'));
    }

    public function test_the_host_can_switch_payout_emails_off(): void
    {
        Notification::fake();
        $host = $this->event->user;
        $host->forceFill([
            'notification_preferences' => array_merge($host->notification_preferences ?? [], ['email_payout_updates' => false]),
        ])->save();

        $this->pay(['payment_type' => 'part', 'amount' => '10.00'])->assertSessionHasNoErrors();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('ticket_payouts', 1);
    }

    public function test_the_hosts_payouts_page_shows_status_and_when_to_expect_payment(): void
    {
        $this->event->forceFill(['event_date' => now()->addDays(10)->toDateString(), 'agreed_payout_on' => null])->save();

        $this->actingAs($this->event->user)
            ->get(route('public-events.tickets.payouts', $this->event))
            ->assertOk()
            ->assertSee('Not paid yet')
            ->assertSee('5 business days after the event')
            ->assertSee($this->event->fresh()->expectedPayoutDate()->format('j M Y'));
    }
}
