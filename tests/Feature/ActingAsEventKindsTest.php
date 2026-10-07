<?php

namespace Tests\Feature;

use App\Enums\HelpRequestKind;
use App\Enums\HelpRequestStatus;
use App\Enums\PublicRegistrationStatus;
use App\Enums\SubscriptionTier;
use App\Enums\TicketingStatus;
use App\Models\Admin;
use App\Models\AdminHelpRequest;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\TicketType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan: plans/admin-create-events.md (Step 5). Each event kind is run end to end inside an
 * acting-as session, with the consent gate on, to find anything that assumed a human client.
 */
class ActingAsEventKindsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['admin.acting_as.enabled' => true]);

        $this->admin = Admin::factory()->create();
        $this->admin->assignRole('super_admin');
    }

    private function clientActedOn(User $client): void
    {
        AdminHelpRequest::query()->create([
            'user_id' => $client->id,
            'kind' => HelpRequestKind::CreateEvent,
            'message' => 'Please set up my event.',
            'status' => HelpRequestStatus::InProgress,
            'assigned_admin_id' => $this->admin->id,
            'claimed_at' => now(),
            'access_expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($this->admin, 'admin')->post(route('admin.users.act-as', $client));
        auth()->shouldUse('web');
    }

    private function exitSession(): void
    {
        $this->delete(route('admin.acting-as.destroy'));
        auth()->shouldUse('admin');
    }

    /**
     * @return array<string, mixed>
     */
    private function invitationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Chanda and Mwila',
            'event_type' => 'birthday',
            'audience' => 'private',
            'product_kind' => 'invitation',
            'host_contact_phone' => '0977123456',
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:30',
            'venue' => 'Garden Terrace',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ], $overrides);
    }

    /** The wizard's layout step, which publishing and submit-for-review both require. */
    private function chooseLayout(Event $event): void
    {
        $template = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $this->patch(route('events.choose-template.update', $event), ['invitation_template_id' => (string) $template->id])
            ->assertSessionHasNoErrors();
    }

    // ── Private invitation ────────────────────────────────────────────────────

    public function test_private_invitation_is_created_and_published_with_the_clients_credit(): void
    {
        $client = User::factory()->withCredits(1)->create(['status' => 'active']);
        $this->clientActedOn($client);

        $this->post(route('events.store'), $this->invitationPayload())->assertSessionHasNoErrors();
        $event = Event::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame($this->admin->id, $event->created_by_admin_id);

        $this->chooseLayout($event);
        $this->patch(route('events.publish', $event))->assertRedirect(route('events.index'));

        $this->assertTrue((bool) $event->fresh()->is_published);
        $this->assertSame(0, $client->fresh()->event_credits, 'the client pays the credit');
        $this->assertSame(0, $this->admin->fresh()->id - $this->admin->id);
    }

    public function test_publishing_with_no_credits_explains_instead_of_dead_ending_on_a_403(): void
    {
        $client = User::factory()->withoutCredits()->create(['status' => 'active']);
        $event = Event::factory()->for($client)->create(['is_published' => false]);
        $this->clientActedOn($client);

        $this->followingRedirects()
            ->from(route('events.edit', $event))
            ->patch(route('events.publish', $event))
            ->assertOk()
            ->assertSee('The client has to do this themselves')
            ->assertSee('You are acting as');

        $this->assertFalse((bool) $event->fresh()->is_published);
    }

    public function test_a_plan_gated_page_bounces_back_with_a_note_instead_of_403(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $this->clientActedOn($client);

        $this->from(route('events.index'))
            ->get(route('billing.show'))
            ->assertRedirect(route('events.index'))
            ->assertSessionHas('acting_notice');
    }

    public function test_payment_actions_stay_a_hard_403(): void
    {
        $client = User::factory()->create(['status' => 'active']);
        $this->clientActedOn($client);

        $this->post(route('payment.initiate'), [])->assertForbidden();
    }

    // ── Ticketed ──────────────────────────────────────────────────────────────

    public function test_ticketed_event_can_be_created_and_submitted_then_the_admin_approves_after_exiting(): void
    {
        $client = User::factory()->withoutCredits()->create(['status' => 'active']);
        $this->clientActedOn($client);

        $this->post(route('events.store'), $this->invitationPayload([
            'name' => 'Client Concert',
            'event_type' => 'concert',
            'audience' => 'public',
            'product_kind' => 'ticketed',
            'ticket_capacity' => '500',
        ]))->assertSessionHasNoErrors();

        $event = Event::query()->where('name', 'Client Concert')->firstOrFail();
        $this->assertSame($client->id, $event->user_id);
        $this->assertSame($this->admin->id, $event->created_by_admin_id);
        $this->assertSame(TicketingStatus::Draft, $event->ticketing_status);

        TicketType::factory()->for($event)->create();

        // The organizer's contact details can be filled in by the admin, but the payout
        // account is the client's alone: posting one while acting is silently not saved.
        $this->patch(route('public-events.organizer.update', $event), [
            'organizer_name' => 'Client Events Ltd',
            'organizer_phone' => '0977123456',
            'organizer_email' => 'client@example.com',
            'organizer_details_public' => '1',
            'payout_account_name' => 'Attacker',
            'payout_account_number' => '999999999',
            'payout_bank' => 'Zanaco (Zambia National Commercial Bank)',
            'payout_branch' => 'Cairo Road',
        ])->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertSame('Client Events Ltd', $event->organizer_name);
        $this->assertNull($event->payout_account_number);

        // Without a payout account the event cannot be submitted for review yet.
        $this->post(route('public-events.ticketing.submit', $event))->assertSessionHasErrors('ticketing');
        $this->assertSame(TicketingStatus::Draft, $event->fresh()->ticketing_status);

        // The client adds their own payout account from their own session.
        $event->forceFill([
            'payout_account_name' => 'Client Events Ltd',
            'payout_account_number' => '0123456789012',
            'payout_bank' => 'Zanaco (Zambia National Commercial Bank)',
            'payout_branch' => 'Cairo Road',
        ])->save();

        $this->post(route('public-events.ticketing.submit', $event))->assertSessionHas('status', 'ticketing-submitted');
        $this->assertSame(TicketingStatus::PendingReview, $event->fresh()->ticketing_status);

        // The approval queue is admin-only, so it is unreachable until the session ends.
        $this->get(route('admin.ticketing.show', $event))->assertForbidden();
        $this->exitSession();
        $this->get(route('admin.ticketing.show', $event))->assertOk();

        $this->assertSame(0, $client->fresh()->event_credits, 'ticketed events never spend a credit');
    }

    // ── Free registration ─────────────────────────────────────────────────────

    public function test_free_registration_needs_the_clients_plan_and_says_so_to_the_admin(): void
    {
        $client = User::factory()->create(['status' => 'active', 'subscription_tier' => SubscriptionTier::None]);
        $this->clientActedOn($client);

        $this->post(route('events.store'), $this->invitationPayload(['audience' => 'public']))
            ->assertSessionHasErrors('audience');

        $message = session('errors')->first('audience');
        $this->assertStringContainsString('Base plan', $message);
        $this->assertStringContainsString('exit this session', $message);
        $this->assertSame(0, Event::query()->where('user_id', $client->id)->count());
    }

    public function test_free_registration_is_submitted_approved_then_the_payment_step_stays_the_clients(): void
    {
        $client = User::factory()->create(['status' => 'active', 'subscription_tier' => SubscriptionTier::Base]);
        $this->clientActedOn($client);

        $this->post(route('events.store'), $this->invitationPayload(['audience' => 'public']))->assertSessionHasNoErrors();
        $event = Event::query()->where('user_id', $client->id)->firstOrFail();
        $this->assertSame(PublicRegistrationStatus::Draft, $event->public_registration_status);

        $this->chooseLayout($event);
        $this->post(route('events.public-registration.submit', $event))
            ->assertSessionHas('status', 'public-registration-submitted');
        $this->assertSame(PublicRegistrationStatus::PendingReview, $event->fresh()->public_registration_status);

        // Approval and the quote are admin-panel actions, so the session ends first.
        $this->exitSession();
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.events.public-registration.approve', $event), ['quote_amount' => 150])
            ->assertSessionHasNoErrors();
        $this->assertSame(PublicRegistrationStatus::Approved, $event->fresh()->public_registration_status);

        // A new session cannot pay for the client.
        $this->post(route('admin.users.act-as', $client));
        auth()->shouldUse('web');
        $this->from(route('events.edit', $event))
            ->get(route('events.public-registration.pay', $event))
            ->assertRedirect(route('events.edit', $event))
            ->assertSessionHas('acting_notice');
        $this->assertNull($event->fresh()->public_registration_quote_paid_at);
    }
}
