<?php

namespace Tests\Feature;

use App\Enums\RsvpApprovalStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\User;
use App\Services\GuestCreator;
use App\Services\GuestDeleter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * plans/guest-deletion-gaps.md Phase 6: a person the host removed who signs themselves up again waits for the host.
 */
class GuestRemovalReentryTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->event = Event::factory()
            ->for(User::factory()->pro()->create())
            ->published()
            ->create(['is_public' => false, 'rsvp_deadline' => null, 'require_rsvp_approval' => false, 'host_contact_phone' => '0977123456']);
    }

    private function removeGuest(array $attributes = []): Guest
    {
        $guest = Guest::factory()->for($this->event)->create($attributes + [
            'name' => 'Removed Person',
            'email' => 'removed@example.test',
            'phone' => '0965000111',
        ]);
        app(GuestDeleter::class)->delete($this->event, $guest);

        return $guest;
    }

    private function signUp(array $overrides = [], ?Event $event = null)
    {
        return $this->post(route('rsvp.open.store', ['slug' => ($event ?? $this->event)->slug]), array_merge([
            'name' => 'Removed Person',
            'email' => 'removed@example.test',
            'phone' => '0965000111',
            'status' => 'accepted',
            'attendee_count' => 1,
        ], $overrides));
    }

    // ---------------------------------------------------------------- the open form

    public function test_a_removed_person_signing_up_with_the_same_email_waits_for_the_host(): void
    {
        $this->removeGuest();

        $this->signUp(['phone' => '0965999888'])->assertSessionHasNoErrors();

        $guest = Guest::query()->where('email', 'removed@example.test')->firstOrFail();
        $this->assertNotNull($guest->previously_removed_at);
        $this->assertSame(RsvpApprovalStatus::Pending, $guest->rsvp->host_approval_status);
    }

    public function test_a_different_email_but_the_same_phone_is_still_recognised(): void
    {
        $this->removeGuest();

        $this->signUp(['email' => 'other@example.test', 'phone' => '+260 96 500 0111'])->assertSessionHasNoErrors();

        $guest = Guest::query()->where('email', 'other@example.test')->firstOrFail();
        $this->assertNotNull($guest->previously_removed_at);
        $this->assertSame(RsvpApprovalStatus::Pending, $guest->rsvp->host_approval_status);
    }

    public function test_the_email_match_ignores_case(): void
    {
        $this->removeGuest(['email' => 'Removed@Example.test', 'phone' => '0965000111']);

        $this->signUp(['email' => 'removed@example.TEST', 'phone' => '0966111222'])->assertSessionHasNoErrors();

        $this->assertNotNull(Guest::query()->firstOrFail()->previously_removed_at);
    }

    public function test_a_stranger_is_not_held(): void
    {
        $this->removeGuest();

        $this->signUp(['name' => 'New Person', 'email' => 'new@example.test', 'phone' => '0966111222'])->assertSessionHasNoErrors();

        $guest = Guest::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertNull($guest->previously_removed_at);
        $this->assertSame(RsvpApprovalStatus::NotRequired, $guest->rsvp->host_approval_status);
    }

    public function test_a_removal_from_another_event_does_not_count(): void
    {
        $other = Event::factory()->for($this->event->user)->published()->create(['is_public' => false, 'rsvp_deadline' => null]);
        $guest = Guest::factory()->for($other)->create(['email' => 'removed@example.test', 'phone' => '0965000111']);
        app(GuestDeleter::class)->delete($other, $guest);

        $this->signUp()->assertSessionHasNoErrors();

        $this->assertNull(Guest::query()->where('event_id', $this->event->id)->firstOrFail()->previously_removed_at);
    }

    public function test_the_hold_applies_even_when_the_event_does_not_review_signups(): void
    {
        $this->assertFalse((bool) $this->event->require_rsvp_approval);
        $this->assertFalse($this->event->hasRsvpApprovalQueue());
        $this->removeGuest();

        $this->signUp()->assertSessionHasNoErrors();

        $this->assertTrue($this->event->fresh()->hasRsvpApprovalQueue());
    }

    // ---------------------------------------------------------------- what bypasses it

    public function test_the_hosts_own_add_is_never_held(): void
    {
        $this->removeGuest();

        $guest = app(GuestCreator::class)->create($this->event, ['name' => 'Removed Person', 'email' => 'removed@example.test', 'phone' => '0965000111']);

        $this->assertNull($guest->previously_removed_at);
    }

    public function test_a_declined_answer_from_a_removed_person_is_not_a_review(): void
    {
        $this->removeGuest();

        $this->signUp(['status' => 'declined', 'attendee_count' => 0])->assertSessionHasNoErrors();

        $guest = Guest::query()->firstOrFail();
        $this->assertNotNull($guest->previously_removed_at);
        $this->assertNotSame(RsvpApprovalStatus::Pending, $guest->rsvp->host_approval_status);
    }

    // ---------------------------------------------------------------- group link

    public function test_a_removed_person_joining_through_a_group_link_is_labelled(): void
    {
        $group = GuestGroup::factory()->for($this->event)->create(['name' => 'Committee']);
        $group->enableLink(5);
        $this->removeGuest();

        $this->post(route('group-rsvp.store', ['token' => $group->fresh()->rsvp_token]), [
            'name' => 'Removed Person',
            'email' => 'removed@example.test',
            'phone' => '0965000111',
            'attendee_count' => 1,
        ])->assertSessionHasNoErrors();

        $guest = Guest::query()->where('email', 'removed@example.test')->firstOrFail();
        $this->assertNotNull($guest->previously_removed_at);
        $this->assertSame(RsvpApprovalStatus::Pending, $guest->rsvp->host_approval_status);
    }

    // ---------------------------------------------------------------- what the host sees

    public function test_the_guest_list_labels_a_returning_person_and_the_api_exposes_it(): void
    {
        $this->removeGuest();
        $this->signUp()->assertSessionHasNoErrors();
        $guest = Guest::query()->firstOrFail();
        $host = $this->event->user;

        $this->actingAs($host)
            ->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertSee('Previously removed')
            ->assertSee('Awaiting approval');

        Sanctum::actingAs($host);
        $this->getJson("/api/v1/host/events/{$this->event->id}/guests/{$guest->id}")
            ->assertOk()
            ->assertJsonPath('guest.previously_removed', true);
    }

    public function test_an_ordinary_guest_is_not_labelled(): void
    {
        $guest = Guest::factory()->for($this->event)->create();
        $host = $this->event->user;

        $this->actingAs($host)
            ->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertDontSee('Previously removed');

        Sanctum::actingAs($host);
        $this->getJson("/api/v1/host/events/{$this->event->id}/guests/{$guest->id}")
            ->assertJsonPath('guest.previously_removed', false);
    }
}
