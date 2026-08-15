<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\User;
use App\Services\EventCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventCreditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Chanda & Mwila Wedding',
            'event_type' => 'wedding',
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'event_time' => '14:00',
            'venue' => 'Lusaka Grand',
        ], $overrides);
    }

    public function test_creating_a_draft_does_not_spend_a_credit(): void
    {
        $user = User::factory()->withCredits(1)->create();

        $this->actingAs($user)
            ->post(route('events.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame(1, $user->fresh()->event_credits);
        $this->assertSame(1, Event::query()->where('user_id', $user->id)->count());
        $this->assertFalse((bool) Event::query()->where('user_id', $user->id)->first()->is_published);
    }

    public function test_a_draft_can_be_created_without_credits(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->actingAs($user)
            ->get(route('events.create'))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('events.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame(0, $user->fresh()->event_credits);
        $this->assertSame(1, Event::query()->where('user_id', $user->id)->count());
    }

    public function test_publishing_an_event_spends_exactly_one_credit(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->for($user)->create(['is_published' => false]);

        $this->actingAs($user)
            ->patch(route('events.publish', $event))
            ->assertRedirect(route('events.public', $event->fresh()->slug));

        $this->assertTrue((bool) $event->fresh()->is_published);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_publishing_via_the_edit_form_spends_exactly_one_credit(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->for($user)->create(['is_published' => false]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->payload([
                'name' => $event->name,
                'event_type' => $event->event_type,
                'event_date' => $event->event_date->format('Y-m-d'),
                'publish' => '1',
            ]))
            ->assertRedirect(route('events.public', $event->fresh()->slug));

        $this->assertTrue((bool) $event->fresh()->is_published);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_publishing_without_credits_is_refused_and_leaves_the_draft(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $event = Event::factory()->for($user)->create(['is_published' => false]);

        $this->actingAs($user)
            ->patch(route('events.publish', $event))
            ->assertRedirect(route('billing.show'))
            ->assertSessionHas('status', 'no-event-credits');

        $this->assertFalse((bool) $event->fresh()->is_published);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    /**
     * The pre-check is only a friendly redirect. This drops the balance to zero
     * behind its back, so the row-locked check inside the transaction is the
     * only thing that can refuse — and it must leave the event unpublished.
     */
    public function test_the_locked_balance_check_refuses_publish_without_going_live(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->for($user)->create(['is_published' => false]);

        User::query()->whereKey($user->id)->update(['event_credits' => 0]);

        $this->actingAs($user)
            ->patch(route('events.publish', $event))
            ->assertRedirect(route('billing.show'))
            ->assertSessionHas('status', 'no-event-credits');

        $this->assertFalse((bool) $event->fresh()->is_published);
        $this->assertSame(0, CreditTransaction::query()->where('event_id', $event->id)->count());
    }

    public function test_republishing_does_not_spend_a_second_credit(): void
    {
        $user = User::factory()->withCredits(2)->create();
        $event = Event::factory()->for($user)->create(['is_published' => false]);

        $this->actingAs($user)->patch(route('events.publish', $event));
        $event->update(['is_published' => false]);

        $this->actingAs($user)
            ->patch(route('events.publish', $event))
            ->assertRedirect(route('events.public', $event->fresh()->slug));

        $this->assertTrue((bool) $event->fresh()->is_published);
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_a_legacy_draft_that_already_paid_at_create_publishes_for_free(): void
    {
        $user = User::factory()->withCredits(2)->create();
        $event = Event::factory()->for($user)->create(['is_published' => false]);

        app(EventCreditService::class)->spend($user, CreditTransaction::REASON_EVENT_CREATED, $event);

        $this->actingAs($user)
            ->patch(route('events.publish', $event))
            ->assertRedirect(route('events.public', $event->fresh()->slug));

        $this->assertTrue((bool) $event->fresh()->is_published);
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_redefining_a_past_event_spends_a_credit(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->published()->create([
            'user_id' => $user->id,
            'name' => 'Original Event',
            'event_date' => now()->subWeek()->format('Y-m-d'),
        ]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->payload([
                'name' => 'Recycled Event',
                'event_date' => now()->addMonth()->format('Y-m-d'),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Recycled Event', $event->fresh()->name);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_redefining_a_past_event_is_refused_without_credits(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $event = Event::factory()->published()->create([
            'user_id' => $user->id,
            'name' => 'Original Event',
            'event_date' => now()->subWeek()->format('Y-m-d'),
        ]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->payload([
                'name' => 'Recycled Event',
                'event_date' => now()->addMonth()->format('Y-m-d'),
            ]))
            ->assertRedirect(route('billing.show'))
            ->assertSessionHas('status', 'no-credits-to-redefine');

        $this->assertSame('Original Event', $event->fresh()->name);
    }

    public function test_non_identity_edits_to_a_past_event_stay_free(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $event = Event::factory()->published()->create([
            'user_id' => $user->id,
            'name' => 'Original Event',
            'event_type' => 'wedding',
            'event_date' => now()->subWeek()->format('Y-m-d'),
            'venue' => 'Old Venue',
        ]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), [
                'name' => 'Original Event',
                'event_type' => 'wedding',
                'event_date' => $event->event_date->format('Y-m-d'),
                'event_time' => '18:30',
                'venue' => 'New Venue',
                'description' => 'Thanks to everyone who came.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('New Venue', $event->fresh()->venue);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_publishing_a_past_draft_still_spends_a_credit(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->create([
            'user_id' => $user->id,
            'event_date' => now()->subWeek()->format('Y-m-d'),
            'is_published' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('events.publish', $event))
            ->assertRedirect(route('events.public', $event->fresh()->slug));

        $this->assertTrue($event->fresh()->is_published);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_an_upcoming_event_is_still_fully_editable(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->create([
            'user_id' => $user->id,
            'event_date' => now()->addMonth()->format('Y-m-d'),
        ]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->payload(['name' => 'Renamed Event']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Event', $event->fresh()->name);

        $this->actingAs($user)
            ->get(route('events.edit', $event))
            ->assertOk();
    }

    public function test_the_event_list_marks_a_past_event_completed(): void
    {
        $user = User::factory()->withCredits(1)->create();
        Event::factory()->published()->create([
            'user_id' => $user->id,
            'event_date' => now()->subWeek()->format('Y-m-d'),
        ]);

        $this->actingAs($user)
            ->get(route('events.index'))
            ->assertOk()
            ->assertSee('Completed', escape: false);
    }

    public function test_the_edit_page_warns_that_redefining_costs_a_credit(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $past = Event::factory()->published()->create([
            'user_id' => $user->id,
            'event_date' => now()->subWeek()->format('Y-m-d'),
        ]);
        $upcoming = Event::factory()->create([
            'user_id' => $user->id,
            'event_date' => now()->addMonth()->format('Y-m-d'),
        ]);

        $this->actingAs($user)
            ->get(route('events.edit', $past))
            ->assertOk()
            ->assertSee('already taken place', escape: false)
            ->assertSee('data-redefine-confirm', escape: false);

        $this->actingAs($user)
            ->get(route('events.edit', $upcoming))
            ->assertOk()
            ->assertDontSee('already taken place', escape: false)
            ->assertSee('Publishing uses 1 event credit', escape: false)
            ->assertSee('data-publish-confirm', escape: false);
    }

    public function test_an_event_on_its_own_date_is_not_locked(): void
    {
        $event = Event::factory()->make(['event_date' => now()->format('Y-m-d')]);

        $this->assertFalse($event->isLocked());
    }
}
