<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Going back through the create wizard: finished steps link back, every step has a labelled way back, and pressing
 * Back then Next on the details step continues the draft just made instead of creating a second event.
 */
class EventWizardBackNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->create();
    }

    private function payload(array $overrides = []): array
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

    // ------------------------------------------------------------ stepper links and back links

    public function test_the_layout_step_links_back_to_the_details_and_marks_the_earlier_steps_done(): void
    {
        $event = Event::factory()->for($this->host)->create(['invitation_template_id' => null]);

        $html = $this->actingAs($this->host)->get(route('events.choose-template', $event))->assertOk()->getContent();

        // Steps 1 and 2 are links to the edit page; the current step 3 is not a link.
        $this->assertSame(2, substr_count($html, 'class="evt-step-link"'));
        $this->assertStringContainsString('href="'.route('events.edit', $event).'" class="evt-step-link"', $html);
        $this->assertStringContainsString('Back to details', $html);
    }

    public function test_the_edit_page_links_back_to_the_layout_and_never_to_itself(): void
    {
        $event = Event::factory()->for($this->host)->create();

        $html = $this->actingAs($this->host)->get(route('events.edit', $event))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('events.choose-template', $event).'" class="evt-step-link"', $html);
        $this->assertStringNotContainsString('href="'.route('events.edit', $event).'" class="evt-step-link"', $html);
        $this->assertStringContainsString('Back to layout', $html);
    }

    public function test_the_ticket_step_links_back_to_the_details_even_before_a_ticket_type_exists(): void
    {
        $event = Event::factory()->for($this->host)->ticketed()->create(['ticket_capacity' => 300]);
        $detailsUrl = route('events.edit', ['event' => $event, 'details' => 1]);

        $html = $this->actingAs($this->host)->get(route('public-events.ticket-types.index', $event))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.$detailsUrl.'" class="evt-step-link"', $html);

        // The plain edit page is still the closed review step, but the stepper's link opens the details form.
        $this->actingAs($this->host)->get(route('events.edit', $event))->assertRedirect(route('public-events.ticket-types.index', $event));
        $this->actingAs($this->host)->get($detailsUrl)->assertOk();
    }

    public function test_the_details_step_has_a_back_link_to_how_people_join_and_no_step_links_before_the_event_exists(): void
    {
        $html = $this->actingAs($this->host)
            ->get(route('events.create', ['audience' => 'private']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('fa-arrow-left"></i> Back', $html);
        $this->assertStringNotContainsString('evt-step-link', $html);
    }

    // ------------------------------------------------------------ Back then Next does not duplicate

    public function test_pressing_next_twice_on_the_details_step_continues_the_same_draft(): void
    {
        $this->actingAs($this->host);

        $first = $this->post(route('events.store'), $this->payload());
        $event = Event::query()->where('user_id', $this->host->id)->firstOrFail();
        $first->assertRedirect(route('events.choose-template', $event));

        // Back, then Next again with the same details.
        $this->post(route('events.store'), $this->payload())
            ->assertRedirect(route('events.choose-template', $event))
            ->assertSessionHas('draft_reused', true);

        $this->assertSame(1, Event::query()->where('user_id', $this->host->id)->count());

        $this->followingRedirects()->get(route('events.choose-template', $event));
    }

    public function test_the_reused_draft_goes_to_the_edit_page_once_a_layout_was_chosen(): void
    {
        $this->actingAs($this->host);
        $this->post(route('events.store'), $this->payload());
        $event = Event::query()->where('user_id', $this->host->id)->firstOrFail();
        $event->update(['invitation_template_id' => InvitationTemplate::query()->value('id')]);

        $this->post(route('events.store'), $this->payload())->assertRedirect(route('events.edit', $event));
        $this->assertSame(1, Event::query()->where('user_id', $this->host->id)->count());
    }

    public function test_a_notice_explains_why_the_existing_draft_opened(): void
    {
        $this->actingAs($this->host);
        $this->post(route('events.store'), $this->payload());

        $this->followingRedirects()
            ->post(route('events.store'), $this->payload())
            ->assertSee('You already created this event a moment ago', false);
    }

    public function test_a_different_name_or_date_is_a_new_event(): void
    {
        $this->actingAs($this->host);
        $this->post(route('events.store'), $this->payload());
        $this->post(route('events.store'), $this->payload(['name' => 'Another party']));
        $this->post(route('events.store'), $this->payload(['event_date' => now()->addWeeks(2)->format('Y-m-d')]));

        $this->assertSame(3, Event::query()->where('user_id', $this->host->id)->count());
    }

    public function test_an_older_draft_or_a_published_one_is_not_reused(): void
    {
        $this->actingAs($this->host);
        $this->post(route('events.store'), $this->payload());
        $event = Event::query()->where('user_id', $this->host->id)->firstOrFail();

        $this->travel(11)->minutes();
        $this->post(route('events.store'), $this->payload());
        $this->assertSame(2, Event::query()->where('user_id', $this->host->id)->count(), 'older than the window is a new event');

        Event::query()->where('user_id', $this->host->id)->update(['is_published' => true]);
        $this->post(route('events.store'), $this->payload());
        $this->assertSame(3, Event::query()->where('user_id', $this->host->id)->count(), 'a published event is never reused');
        $this->assertNotNull($event->fresh());
    }

    public function test_another_hosts_draft_is_never_reused(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('events.store'), $this->payload());

        $this->actingAs($this->host)->post(route('events.store'), $this->payload());

        $this->assertSame(1, Event::query()->where('user_id', $this->host->id)->count());
        $this->assertSame(1, Event::query()->where('user_id', $other->id)->count());
    }

    public function test_resubmitting_at_the_draft_limit_continues_the_draft_instead_of_the_limit_message(): void
    {
        $this->actingAs($this->host);
        $this->post(route('events.store'), $this->payload());
        $event = Event::query()->where('user_id', $this->host->id)->firstOrFail();

        Event::factory()->count(Event::MAX_OPEN_DRAFTS - 1)->for($this->host)->create(['is_published' => false]);
        $this->assertGreaterThanOrEqual(Event::MAX_OPEN_DRAFTS, Event::openDraftCountFor($this->host->id));

        $this->post(route('events.store'), $this->payload())
            ->assertRedirect(route('events.choose-template', $event))
            ->assertSessionMissing('status');
    }
}
