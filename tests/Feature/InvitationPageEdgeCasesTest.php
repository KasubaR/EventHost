<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan: plans/invitation-page-edge-cases.md. Phase 1 — an event that was never published shows no status
 * page: deleted, cancelled or paused, it is a plain 404 and its name never appears.
 */
class InvitationPageEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function event(bool $published, array $overrides = []): Event
    {
        return Event::factory()->for(User::factory()->create())->create(array_merge([
            'name' => 'Secret Draft Party',
            'slug' => 'secret-draft',
            'is_published' => $published,
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'rsvp_deadline' => null,
        ], $overrides));
    }

    /** @return array<string, array{0: callable(Event): void}> */
    public static function lifecycleStates(): array
    {
        return [
            'deleted' => [fn (Event $e) => $e->delete()],
            'cancelled' => [fn (Event $e) => $e->forceFill(['cancelled_at' => now()])->save()],
            'paused' => [fn (Event $e) => $e->forceFill(['invitation_paused_at' => now()])->save()],
        ];
    }

    /**
     * @dataProvider lifecycleStates
     */
    public function test_a_never_published_event_is_a_plain_404_on_every_page(callable $apply): void
    {
        $event = $this->event(published: false);
        $apply($event);

        $this->get(route('events.public', $event->slug))->assertNotFound()->assertDontSee('Secret Draft Party');
        $this->get(route('events.public.ics', $event->slug))->assertNotFound();
        $this->get(route('rsvp.open.show', $event->slug))->assertNotFound()->assertDontSee('Secret Draft Party');
        $this->getJson("/api/v1/events/{$event->slug}")->assertNotFound();
    }

    /**
     * @dataProvider lifecycleStates
     */
    public function test_a_published_event_keeps_its_status_page(callable $apply): void
    {
        $event = $this->event(published: true);
        $apply($event);

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee(match (true) {
                $event->trashed() => 'Invitation no longer available',
                $event->isCancelled() => 'Event cancelled',
                default => 'Invitation unavailable',
            }, escape: false);
    }

    public function test_a_published_deleted_event_still_reports_gone_in_the_api(): void
    {
        $event = $this->event(published: true);
        $event->delete();

        $this->getJson("/api/v1/events/{$event->slug}")->assertOk()->assertJsonPath('status', 'gone');
    }
}
