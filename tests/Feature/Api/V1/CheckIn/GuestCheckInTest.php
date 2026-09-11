<?php

namespace Tests\Feature\Api\V1\CheckIn;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice D — GET/POST /api/v1/host/events/{event}/checkin/*. Api\V1\EventCheckInController
 * is a near-verbatim port of App\Http\Controllers\CheckInController (already JSON on
 * the web side), so these tests mirror tests/Feature/CheckInTest.php's proof shape
 * against the Sanctum-guarded route instead of the session-guarded one.
 */
class GuestCheckInTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function eventOnToday(User $owner, array $overrides = []): Event
    {
        $localNow = now()->timezone(config('events.timezone'));

        return Event::factory()->for($owner)->create(array_merge([
            'event_date' => $localNow->toDateString(),
            'event_time' => $localNow->format('H:i:s'),
        ], $overrides));
    }

    public function test_confirming_by_token_checks_the_guest_in_exactly_once(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventOnToday($owner);
        $guest = Guest::factory()->for($event)->create();

        $confirm = fn () => $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson(route('api.v1.host.events.checkin.confirm-token', ['event' => $event, 'token' => $guest->invitation_token]));

        $first = $confirm();
        $first->assertOk()->assertJsonPath('already_checked_in', false);
        $first->assertJsonPath('guest.name', $guest->name);

        $checkedInAt = $guest->refresh()->checked_in_at;
        $this->assertNotNull($checkedInAt);
        $this->assertSame($owner->id, $guest->checked_in_by);

        // Re-scanning the same guest must never move the timestamp or double-write —
        // this is the row-locked idempotency the Slice D "Done when" bar calls for.
        $second = $confirm();
        $second->assertOk()->assertJsonPath('already_checked_in', true);
        $this->assertTrue($guest->refresh()->checked_in_at->equalTo($checkedInAt));
    }

    public function test_lookup_never_confirms_anyone(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventOnToday($owner);
        $guest = Guest::factory()->for($event)->create(['name' => 'Findable Fiona']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson(route('api.v1.host.events.checkin.lookup', ['event' => $event, 'q' => 'Fiona']));

        $response->assertOk();
        $response->assertJsonPath('guests.0.id', $guest->id);
        $this->assertNull($guest->refresh()->checked_in_at);
    }

    public function test_checkin_404s_on_a_ticketed_event(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $guest = Guest::factory()->for($event)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson(route('api.v1.host.events.checkin.confirm-token', ['event' => $event, 'token' => $guest->invitation_token]))
            ->assertNotFound();
    }

    public function test_base_tier_owner_is_forbidden_not_redirected(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventOnToday($owner);
        $guest = Guest::factory()->for($event)->create();

        // Web redirects to billing here — the API has no page to redirect to, so it's
        // a plain 403 the app renders itself (see the controller's docblock).
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson(route('api.v1.host.events.checkin.confirm-token', ['event' => $event, 'token' => $guest->invitation_token]))
            ->assertForbidden();
    }
}
