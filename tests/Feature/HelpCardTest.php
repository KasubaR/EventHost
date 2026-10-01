<?php

namespace Tests\Feature;

use App\Enums\HelpRequestKind;
use App\Enums\HelpRequestStatus;
use App\Models\AdminHelpRequest;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "ask our team" card on My Events and the event edit page (plans/admin-create-events.md).
 */
class HelpCardTest extends TestCase
{
    use RefreshDatabase;

    private function openRequest(User $user, HelpRequestStatus $status = HelpRequestStatus::Open): void
    {
        AdminHelpRequest::query()->create([
            'user_id' => $user->id,
            'kind' => HelpRequestKind::Other,
            'message' => 'Please help me with my event.',
            'status' => $status,
            'access_expires_at' => $status === HelpRequestStatus::InProgress ? now()->addDays(3) : null,
        ]);
    }

    public function test_card_is_hidden_while_team_help_is_switched_off(): void
    {
        config(['admin.acting_as.enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('events.index'))
            ->assertOk()
            ->assertDontSee('help-card', false)
            ->assertDontSee('Get help');
    }

    public function test_index_shows_the_card_with_a_create_link_and_the_logo(): void
    {
        config(['admin.acting_as.enabled' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('events.index'))
            ->assertOk()
            ->assertSee('Want us to do it for you?')
            ->assertSee('Ask our team')
            ->assertSee(route('help-request.show', ['kind' => 'create_event']), false)
            ->assertSee('EventHost Logo_Icon.svg', false)
            ->assertSee('Get help');
    }

    public function test_edit_page_card_names_the_event_and_there_is_no_header_button(): void
    {
        config(['admin.acting_as.enabled' => true]);
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->privateAudience()->create();

        $this->actingAs($user)->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('Stuck on this event?')
            ->assertSee(route('help-request.show', ['event' => $event->id]), false)
            ->assertDontSee('Ask our team for help');
    }

    public function test_card_becomes_a_status_line_while_a_request_is_open(): void
    {
        config(['admin.acting_as.enabled' => true]);
        $user = User::factory()->create();
        $this->openRequest($user);

        $this->actingAs($user)->get(route('events.index'))
            ->assertOk()
            ->assertSee('Your request is with our team')
            ->assertSee('View request')
            ->assertDontSee('Want us to do it for you?');
    }

    public function test_card_says_when_the_team_is_working_on_it(): void
    {
        config(['admin.acting_as.enabled' => true]);
        $user = User::factory()->create();
        $this->openRequest($user, HelpRequestStatus::InProgress);

        $this->actingAs($user)->get(route('events.index'))->assertSee('Our team is working on your request');
    }

    public function test_the_empty_state_has_one_create_button_and_no_leftover_help_button(): void
    {
        config(['admin.acting_as.enabled' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('events.index'))->assertOk()->assertSee('No Events Yet');
        $html = $response->getContent();

        // An earlier edit duplicated the old header button into the empty state and left a div open.
        $this->assertSame(1, substr_count($html, 'Create your first invitation'));
        $this->assertSame(0, substr_count($html, 'Ask our team to create it'));
        $this->assertSame(1, substr_count($html, 'class="dash-empty"'));

        // The empty-state block must close itself: opens and closes between its start and the
        // end of its own container balance.
        $block = substr($html, strpos($html, 'class="dash-empty"'));
        $block = substr($block, 0, strpos($block, 'Create event') + strlen('Create event'));
        $this->assertSame(1, substr_count($block, '<div'), 'only the dash-empty-icon div opens before the Create event link');
    }
}
