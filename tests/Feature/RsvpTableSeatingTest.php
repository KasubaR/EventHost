<?php

namespace Tests\Feature;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-status-changes.md Phase 6: a guest who declines gives up their table, and nobody gets a printed QR badge
 * the door would refuse.
 */
class RsvpTableSeatingTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    private EventTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->host = User::factory()->pro()->create();
        $this->event = Event::factory()->for($this->host)->published()->create(['rsvp_deadline' => null, 'allow_plus_one' => true]);
        $this->table = EventTable::factory()->for($this->event)->create(['label' => 'Table 5']);
    }

    private function guest(?RsvpStatus $status, RsvpApprovalStatus $approval = RsvpApprovalStatus::NotRequired, bool $seated = true): Guest
    {
        $guest = Guest::factory()->for($this->event)->create(['event_table_id' => $seated ? $this->table->id : null]);

        if ($status !== null) {
            Rsvp::query()->create([
                'event_id' => $this->event->id,
                'guest_id' => $guest->id,
                'status' => $status,
                'attendee_count' => $status === RsvpStatus::Accepted ? 1 : 0,
                'host_approval_status' => $approval,
            ]);
        }

        return $guest;
    }

    private function answer(Guest $guest, string $status)
    {
        return $this->post(route('rsvp.token.store', $guest->invitation_token), ['status' => $status, 'attendee_count' => $status === 'accepted' ? 1 : 0]);
    }

    public function test_declining_gives_up_the_table(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);

        $this->answer($guest, 'declined')->assertSessionHasNoErrors();

        $this->assertNull($guest->fresh()->event_table_id);
    }

    public function test_maybe_keeps_the_table(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);

        $this->answer($guest, 'maybe')->assertSessionHasNoErrors();

        $this->assertSame($this->table->id, $guest->fresh()->event_table_id);
    }

    public function test_the_host_setting_a_decline_also_frees_the_table(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);

        $this->actingAs($this->host)
            ->patch(route('events.guests.rsvp.set', ['event' => $this->event, 'guest' => $guest->id]), ['status' => 'declined'])
            ->assertSessionHasNoErrors();

        $this->assertNull($guest->fresh()->event_table_id);
    }

    public function test_a_table_the_host_gives_to_someone_who_already_declined_is_kept_on_a_repeat_decline(): void
    {
        $guest = $this->guest(RsvpStatus::Declined, seated: false);
        $guest->forceFill(['event_table_id' => $this->table->id])->save();

        $this->answer($guest, 'declined')->assertSessionHasNoErrors();

        $this->assertSame($this->table->id, $guest->fresh()->event_table_id, 'only the move INTO declined frees a seat');
    }

    public function test_coming_back_does_not_bring_the_table_back(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);
        $this->answer($guest, 'declined');

        $this->answer($guest, 'accepted')->assertSessionHasNoErrors();

        $this->assertNull($guest->fresh()->event_table_id, 'the host reseats them');
    }

    public function test_the_badge_scope_drops_declined_and_rejected_guests_and_keeps_everyone_else(): void
    {
        $kept = [
            'accepted' => $this->guest(RsvpStatus::Accepted),
            'maybe' => $this->guest(RsvpStatus::Maybe),
            'no answer' => $this->guest(null),
            'awaiting approval' => $this->guest(RsvpStatus::Accepted, RsvpApprovalStatus::Pending),
            'approved' => $this->guest(RsvpStatus::Accepted, RsvpApprovalStatus::Approved),
        ];
        $declined = $this->guest(RsvpStatus::Declined);
        $rejected = $this->guest(RsvpStatus::Accepted, RsvpApprovalStatus::Rejected);

        $ids = $this->event->guests()->wantedAtTheDoor()->pluck('id')->all();

        foreach ($kept as $label => $guest) {
            $this->assertContains($guest->id, $ids, $label);
        }
        $this->assertNotContains($declined->id, $ids);
        $this->assertNotContains($rejected->id, $ids);
    }

    public function test_the_badge_sheet_still_downloads(): void
    {
        $this->guest(RsvpStatus::Accepted);
        $this->guest(RsvpStatus::Declined);

        $this->actingAs($this->host)->get(route('events.guests.qr-sheet', $this->event))->assertOk();
    }
}
