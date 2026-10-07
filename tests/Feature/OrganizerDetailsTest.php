<?php

namespace Tests\Feature;

use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Support\ZambianBanks;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wizard step 4 for a ticketed event ("Organizer Details"): organizer contact, whether it
 * shows on the event page, and the payout account. Plus the Contact & Help card.
 */
class OrganizerDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * A draft ticketed event that has a ticket but no organizer details yet.
     */
    private function draftWithTicket(User $user, array $overrides = []): Event
    {
        $event = Event::factory()->for($user)->ticketed()->create(array_merge([
            'organizer_name' => null,
            'organizer_phone' => null,
            'organizer_email' => null,
            'payout_account_name' => null,
            'payout_account_number' => null,
            'payout_bank' => null,
            'payout_branch' => null,
        ], $overrides));
        TicketType::factory()->for($event)->create(['quantity' => 100]);

        return $event;
    }

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'organizer_name' => 'Nsobe Game Camp',
            'organizer_phone' => '098934342',
            'organizer_email' => 'reservations@nsobe.com.zm',
            'organizer_details_public' => '1',
            'payout_account_name' => 'Nsobe Game Camp Ltd',
            'payout_account_number' => '0123 4567 8901',
            'payout_bank' => 'Zanaco (Zambia National Commercial Bank)',
            'payout_branch' => 'Cairo Road',
        ], $overrides);
    }

    public function test_the_step_needs_a_ticket_type_first(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->get(route('public-events.organizer.edit', $event))
            ->assertRedirect(route('public-events.ticket-types.index', $event));
    }

    public function test_the_page_lists_the_zambian_banks_and_the_payout_fields(): void
    {
        $user = User::factory()->create();
        $event = $this->draftWithTicket($user);

        $response = $this->actingAs($user)->get(route('public-events.organizer.edit', $event))->assertOk();

        foreach (['Account holder name', 'Account number', 'Bank', 'Branch', 'Contact number', 'Contact email'] as $label) {
            $response->assertSee($label, false);
        }

        foreach (ZambianBanks::names() as $bank) {
            $response->assertSee(e($bank), false);
        }
    }

    public function test_saving_in_the_wizard_stores_everything_and_moves_on_to_review(): void
    {
        $user = User::factory()->create();
        $event = $this->draftWithTicket($user);

        $this->actingAs($user)
            ->patch(route('public-events.organizer.update', $event), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('events.edit', $event));

        $event->refresh();
        $this->assertSame('Nsobe Game Camp', $event->organizer_name);
        $this->assertSame('reservations@nsobe.com.zm', $event->organizer_email);
        $this->assertTrue($event->organizer_details_public);
        $this->assertSame('Cairo Road', $event->payout_branch);
        // Spaces typed into the account number are dropped.
        $this->assertSame('012345678901', $event->payout_account_number);
        $this->assertTrue($event->hasOrganizerDetails() && $event->hasPayoutAccount());
    }

    public function test_the_payout_account_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $event = $this->draftWithTicket($user);

        $this->actingAs($user)->patch(route('public-events.organizer.update', $event), $this->payload());

        $raw = DB::table('events')->where('id', $event->id)->first();
        $this->assertStringNotContainsString('012345678901', (string) $raw->payout_account_number);
        $this->assertStringNotContainsString('Nsobe Game Camp Ltd', (string) $raw->payout_account_name);
    }

    public function test_validation_rejects_bad_input(): void
    {
        $user = User::factory()->create();
        $event = $this->draftWithTicket($user);

        $this->actingAs($user)
            ->patch(route('public-events.organizer.update', $event), $this->payload([
                'organizer_email' => 'not-an-email',
                'organizer_phone' => 'call me',
                'payout_account_number' => '12ab',
                'payout_bank' => 'Bank of Nowhere',
                'payout_branch' => '',
                'organizer_details_public' => '',
            ]))
            ->assertSessionHasErrors([
                'organizer_email',
                'organizer_phone',
                'payout_account_number',
                'payout_bank',
                'payout_branch',
                'organizer_details_public',
            ]);

        $this->assertNull($event->fresh()->organizer_name);
    }

    public function test_review_step_stays_closed_until_organizer_details_are_in(): void
    {
        $user = User::factory()->create();
        $event = $this->draftWithTicket($user);

        $this->actingAs($user)
            ->get(route('events.edit', $event))
            ->assertRedirect(route('public-events.organizer.edit', $event))
            ->assertSessionHasErrors('ticket_type');

        // The wizard's back-link to the details form still opens.
        $this->actingAs($user)->get(route('events.edit', ['event' => $event, 'details' => 1]))->assertOk();

        // And so does the review step once the details exist.
        $this->actingAs($user)->patch(route('public-events.organizer.update', $event), $this->payload());
        $this->actingAs($user)->get(route('events.edit', $event))->assertOk();
    }

    public function test_submitting_for_activation_needs_the_organizer_details(): void
    {
        $user = User::factory()->create();
        $event = $this->draftWithTicket($user);

        $this->actingAs($user)
            ->post(route('public-events.ticketing.submit', $event))
            ->assertRedirect(route('public-events.organizer.edit', $event))
            ->assertSessionHasErrors('ticketing');

        $this->assertSame(TicketingStatus::Draft, $event->fresh()->ticketing_status);

        $this->actingAs($user)->patch(route('public-events.organizer.update', $event), $this->payload());

        $this->actingAs($user)
            ->post(route('public-events.ticketing.submit', $event))
            ->assertSessionHas('status', 'ticketing-submitted');
    }

    public function test_only_the_owner_can_use_the_step(): void
    {
        $owner = User::factory()->create();
        $event = $this->draftWithTicket($owner);

        $this->actingAs(User::factory()->create())
            ->get(route('public-events.organizer.edit', $event))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->patch(route('public-events.organizer.update', $event), $this->payload())
            ->assertForbidden();
    }

    public function test_an_approved_event_can_still_edit_its_organizer_details(): void
    {
        $user = User::factory()->create();
        $event = $this->draftWithTicket($user, ['ticketing_status' => TicketingStatus::Approved]);

        $this->actingAs($user)
            ->get(route('public-events.organizer.edit', $event))
            ->assertOk()
            ->assertSee('Save organizer details', false);

        $this->actingAs($user)
            ->patch(route('public-events.organizer.update', $event), $this->payload(['organizer_name' => 'New Name']))
            ->assertRedirect(route('public-events.organizer.edit', $event));

        $this->assertSame('New Name', $event->fresh()->organizer_name);
    }

    // ── Contact & Help card ───────────────────────────────────────────────────

    private function publishedEvent(array $overrides = []): Event
    {
        $event = Event::factory()->for(User::factory()->create())->ticketed()->create(array_merge([
            'is_published' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'organizer_name' => 'Nsobe Game Camp',
            'organizer_phone' => '098934342',
            'organizer_email' => 'reservations@nsobe.com.zm',
            'organizer_details_public' => true,
        ], $overrides));
        TicketType::factory()->for($event)->create();

        return $event;
    }

    public function test_the_event_page_shows_organizer_and_support_when_the_host_allows_it(): void
    {
        $event = $this->publishedEvent();

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee('Contact &amp; Help', false)
            ->assertSee('Questions about this event?', false)
            ->assertSee('Nsobe Game Camp', false)
            ->assertSee('tel:098934342', false)
            ->assertSee('mailto:reservations@nsobe.com.zm', false)
            ->assertSee('Need a hand?', false)
            ->assertSee('mailto:'.config('mail.support_address'), false);
    }

    public function test_the_event_page_keeps_the_organizer_private_when_the_host_says_no(): void
    {
        $event = $this->publishedEvent(['organizer_details_public' => false]);

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee('Need a hand?', false)
            ->assertDontSee('The organizer', false)
            ->assertDontSee('reservations@nsobe.com.zm', false)
            ->assertDontSee('098934342', false);
    }

    public function test_the_event_page_has_no_organizer_block_when_none_was_added(): void
    {
        $event = $this->publishedEvent([
            'organizer_name' => null,
            'organizer_phone' => null,
            'organizer_email' => null,
        ]);

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee('Need a hand?', false)
            ->assertDontSee('The organizer', false);
    }

    public function test_the_payout_account_never_reaches_the_public_page(): void
    {
        $event = $this->publishedEvent([
            'payout_account_number' => '0123456789012',
            'payout_account_name' => 'Secret Holder',
        ]);

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertDontSee('0123456789012', false)
            ->assertDontSee('Secret Holder', false);
    }
}
