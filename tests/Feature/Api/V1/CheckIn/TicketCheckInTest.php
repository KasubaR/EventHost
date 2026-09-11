<?php

namespace Tests\Feature\Api\V1\CheckIn;

use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice D — GET/POST /api/v1/host/events/{event}/tickets/checkin/*. Ticket-side
 * twin of GuestCheckInTest, mirroring tests/Feature/TicketCheckInTest.php's proof
 * shape against the Sanctum-guarded route.
 */
class TicketCheckInTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function ticketedEventOnToday(User $owner, array $overrides = []): Event
    {
        $localNow = now()->timezone(config('events.timezone'));

        return Event::factory()->for($owner)->ticketed()->approved()->create(array_merge([
            'event_date' => $localNow->toDateString(),
            'event_time' => $localNow->format('H:i:s'),
        ], $overrides));
    }

    public function test_confirming_by_token_checks_the_ticket_in_exactly_once(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEventOnToday($owner);
        $type = TicketType::factory()->for($event)->create();
        $ticket = Ticket::factory()->for($event)->for($type, 'ticketType')->create();

        $confirm = fn () => $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson(route('api.v1.host.events.tickets.checkin.confirm-token', ['event' => $event, 'token' => $ticket->public_token]));

        $first = $confirm();
        $first->assertOk()->assertJsonPath('already_checked_in', false);

        $checkedInAt = $ticket->refresh()->checked_in_at;
        $this->assertNotNull($checkedInAt);

        $second = $confirm();
        $second->assertOk()->assertJsonPath('already_checked_in', true);
        $this->assertTrue($ticket->refresh()->checked_in_at->equalTo($checkedInAt));
    }

    public function test_unapproved_ticketed_event_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $type = TicketType::factory()->for($event)->create();
        $ticket = Ticket::factory()->for($event)->for($type, 'ticketType')->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson(route('api.v1.host.events.tickets.checkin.confirm-token', ['event' => $event, 'token' => $ticket->public_token]))
            ->assertForbidden();
    }

    public function test_a_non_ticketed_event_404s_on_the_ticket_checkin_route(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson(route('api.v1.host.events.tickets.checkin.lookup', ['event' => $event]))
            ->assertNotFound();
    }
}
