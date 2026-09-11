<?php

namespace Tests\Feature\Api\V1\Tickets;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice D — /api/v1/host/events/{event}/ticket-types. JSON sibling of
 * App\Http\Controllers\EventTicketTypeController; destroy() returns a
 * 422-shaped error instead of a redirect-with-errors when the type has
 * blocking sales.
 */
class TicketTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_create_a_ticket_type(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson(route('api.v1.host.events.ticket-types.store', $event), [
                'name' => 'General Admission',
                'badge_color' => TicketType::DEFAULT_BADGE_COLOR,
                'price' => '50.00',
                'min_per_order' => 1,
                'max_per_order' => 10,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('ticket_type.name', 'General Admission');
        $this->assertSame(1, TicketType::query()->where('event_id', $event->id)->count());
    }

    public function test_destroy_is_blocked_when_the_type_has_issued_tickets(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $type = TicketType::factory()->for($event)->create();
        Ticket::factory()->for($event)->for($type, 'ticketType')->create(['status' => TicketStatus::Valid]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson(route('api.v1.host.events.ticket-types.destroy', ['event' => $event, 'ticketType' => $type]));

        $response->assertStatus(422);
        $response->assertJsonPath('errors.ticket_type.0', 'This ticket type has holds or issued tickets and cannot be deleted.');
        $this->assertNotNull($type->fresh());
    }
}
