<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\User;
use App\Support\GuestPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * What adding a guest does with a missing email or phone, duplicates, a very long or non-Latin name, two numbers in
 * one field and a badly formatted number — on the host form, the import, the guest-facing forms and the bulk actions.
 */
class GuestAddEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function import(Event $event, string $csv)
    {
        return $this->actingAs($event->user)->post(route('events.guests.import.store', $event), [
            'file' => UploadedFile::fake()->createWithContent('guests.csv', $csv),
        ]);
    }

    // ── Missing contact details ──────────────────────────────────────────────

    public function test_a_name_only_guest_is_saved_and_the_host_is_told_they_cannot_be_reached(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), ['name' => 'Nobody Reachable'])
            ->assertRedirect(route('events.guests.index', $event))
            ->assertSessionHas('guest_unreachable', 'Nobody Reachable');

        $guest = Guest::query()->where('name', 'Nobody Reachable')->firstOrFail();
        $this->assertNull($guest->email);
        $this->assertNull($guest->phone);
        $this->assertNotNull($guest->invitation_token);

        $this->get(route('events.guests.index', $event))
            ->assertOk()
            ->assertSee('has no email or phone')
            ->assertSee('No contact details. Share their link yourself.');
    }

    public function test_a_guest_with_an_email_is_not_flagged_unreachable(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), ['name' => 'Has Email', 'email' => 'has@example.test'])
            ->assertSessionMissing('guest_unreachable');
    }

    public function test_bulk_reminder_skips_guests_with_no_email_and_leaves_their_reminder_unused(): void
    {
        Notification::fake();
        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->create();
        $withEmail = Guest::factory()->for($event)->create(['email' => 'has@example.test']);
        $noEmail = Guest::factory()->for($event)->create(['email' => null]);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'send_reminder_email',
                'guest_ids' => [$withEmail->id, $noEmail->id],
                'days_until' => 3,
            ])
            ->assertSessionHas('bulk_count', 1)
            ->assertSessionHas('bulk_skipped', 1);

        $this->assertSame([], $noEmail->fresh()->rsvp_reminders_sent);
        $this->assertSame(['3'], $withEmail->fresh()->rsvp_reminders_sent);
    }

    public function test_bulk_update_email_counts_only_guests_it_can_email(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $withEmail = Guest::factory()->for($event)->create(['email' => 'has@example.test']);
        $noEmail = Guest::factory()->for($event)->create(['email' => null]);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'send_update_email',
                'guest_ids' => [$withEmail->id, $noEmail->id],
                'update_message' => 'Venue moved.',
            ])
            ->assertSessionHas('bulk_count', 1)
            ->assertSessionHas('bulk_skipped', 1);
    }

    public function test_prepare_whatsapp_share_only_marks_guests_with_a_usable_phone(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $withPhone = Guest::factory()->for($event)->create(['phone' => '0971234567']);
        $noPhone = Guest::factory()->for($event)->create(['phone' => null]);
        $legacyBadPhone = Guest::factory()->for($event)->create(['phone' => '12345']);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'prepare_whatsapp_share',
                'guest_ids' => [$withPhone->id, $noPhone->id, $legacyBadPhone->id],
            ])
            ->assertSessionHas('bulk_count', 1)
            ->assertSessionHas('bulk_skipped', 2);

        $this->assertTrue($withPhone->fresh()->invitation_sent);
        $this->assertFalse($noPhone->fresh()->invitation_sent);
        $this->assertFalse($legacyBadPhone->fresh()->invitation_sent);
    }

    public function test_the_api_bulk_action_reports_skipped_guests(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $noEmail = Guest::factory()->for($event)->create(['email' => null]);

        $this->withHeader('Authorization', 'Bearer '.$owner->createToken('t')->plainTextToken)
            ->postJson("/api/v1/host/events/{$event->id}/guests/bulk", [
                'action' => 'send_update_email',
                'guest_ids' => [$noEmail->id],
                'update_message' => 'Venue moved.',
            ])
            ->assertOk()
            ->assertJsonPath('affected_count', 0)
            ->assertJsonPath('skipped_count', 1);
    }

    // ── Duplicates ───────────────────────────────────────────────────────────

    public function test_two_guests_may_share_a_name_and_the_host_is_told(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $first = Guest::factory()->for($event)->create(['name' => 'John Banda']);

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), ['name' => 'john banda', 'email' => 'john2@example.test'])
            ->assertRedirect(route('events.guests.index', $event))
            ->assertSessionHas('guest_same_name', 'john banda');

        $second = Guest::query()->where('email', 'john2@example.test')->firstOrFail();
        $this->assertNotSame($first->qrDownloadName(), $second->qrDownloadName());
    }

    public function test_the_same_email_in_another_case_is_refused(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['email' => 'taken@example.test']);

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), ['name' => 'Copy', 'email' => ' Taken@Example.TEST '])
            ->assertSessionHasErrors('email');
    }

    public function test_a_foreign_number_sharing_the_last_nine_digits_is_not_a_duplicate(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['phone' => '0971234567']);

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), ['name' => 'Abroad', 'phone' => '+1 971 234 567'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('guests', ['event_id' => $event->id, 'name' => 'Abroad']);
    }

    public function test_import_skips_the_same_number_written_two_ways_in_one_file(): void
    {
        $event = Event::factory()->for(User::factory()->create())->create();

        $this->import($event, "name,email,phone\nFirst,,0971234567\nSecond,,+260 97 123 4567\n")
            ->assertSessionHas('import_created', 1)
            ->assertSessionHas('import_skipped', 1);
    }

    // ── Long and non-Latin names ─────────────────────────────────────────────

    public function test_import_refuses_an_over_long_name_and_keeps_the_rest(): void
    {
        $event = Event::factory()->for(User::factory()->create())->create();
        $long = str_repeat('a', Guest::NAME_MAX + 1);

        $this->import($event, "name,email,phone\n{$long},,\nShort Name,,\n")
            ->assertSessionHas('import_created', 1)
            ->assertSessionHas('import_invalid', 1)
            ->assertSessionHas('import_problems', [['row' => 2, 'message' => 'the name is longer than '.Guest::NAME_MAX.' characters.']]);

        $this->assertSame(['Short Name'], $event->guests()->pluck('name')->all());
    }

    public function test_the_host_form_caps_a_name_at_the_column_length(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), ['name' => str_repeat('b', Guest::NAME_MAX + 1)])
            ->assertSessionHasErrors('name');

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), ['name' => str_repeat('b', Guest::NAME_MAX)])
            ->assertSessionHasNoErrors();
    }

    public function test_the_guest_list_wraps_a_long_name_and_marks_its_direction(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['name' => 'سارة أحمد']);

        $this->actingAs($owner)
            ->get(route('events.guests.index', $event))
            ->assertOk()
            ->assertSee('<td class="evt-guest-name-cell" dir="auto">سارة أحمد</td>', escape: false);
    }

    public function test_non_latin_names_are_stored_as_typed_and_normalised(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), ['name' => "  Chis\u{0065}\u{0301}  Mwansa  "])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('guests', ['event_id' => $event->id, 'name' => "Chis\u{00E9} Mwansa"]);

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), ['name' => '王小明', 'email' => 'wang@example.test'])
            ->assertSessionHasNoErrors();

        $guest = Guest::query()->where('email', 'wang@example.test')->firstOrFail();
        $this->assertSame('王小明', $guest->name);
        $this->assertStringStartsWith('guest-'.$guest->id, $guest->qrDownloadName());
    }

    public function test_import_refuses_an_invalid_email_like_the_form_does(): void
    {
        $event = Event::factory()->for(User::factory()->create())->create();

        $this->import($event, "name,email,phone\nBad Email,not-an-email,\nGood,good@example.test,\n")
            ->assertSessionHas('import_created', 1)
            ->assertSessionHas('import_invalid', 1);

        $this->assertDatabaseMissing('guests', ['name' => 'Bad Email']);
    }

    // ── Phone format and two numbers ─────────────────────────────────────────

    public function test_the_host_form_refuses_a_badly_formatted_or_doubled_phone(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        foreach (['097123456' => GuestPhone::ZAMBIAN_MESSAGE, 'call me' => GuestPhone::CHARACTERS_MESSAGE, '0971111111 / 0972222222' => GuestPhone::MULTIPLE_MESSAGE] as $phone => $message) {
            $this->actingAs($owner)
                ->post(route('events.guests.store', $event), ['name' => 'Bad Phone', 'phone' => $phone])
                ->assertSessionHasErrors(['phone' => $message]);
        }

        $this->assertDatabaseMissing('guests', ['name' => 'Bad Phone']);
    }

    public function test_editing_a_guest_keeps_an_old_phone_the_host_did_not_touch(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['name' => 'Old', 'phone' => '12345']);

        $this->actingAs($owner)
            ->patch(route('events.guests.update', ['event' => $event, 'guest' => $guest->id]), ['name' => 'Renamed', 'phone' => '12345'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $guest->fresh()->name);

        $this->actingAs($owner)
            ->patch(route('events.guests.update', ['event' => $event, 'guest' => $guest->id]), ['name' => 'Renamed', 'phone' => '54321'])
            ->assertSessionHasErrors('phone');
    }

    public function test_import_refuses_bad_phones_with_the_row_and_reason(): void
    {
        $event = Event::factory()->for(User::factory()->create())->create();

        $this->import($event, "name,email,phone,group\nTwo Numbers,,0971111111 / 0972222222,Family\nNo Digits,,TBD,Family\nFine,,0961234567,\n")
            ->assertSessionHas('import_created', 1)
            ->assertSessionHas('import_invalid', 2)
            ->assertSessionHas('import_problems', [
                ['row' => 2, 'message' => 'phone "0971111111 / 0972222222": '.lcfirst(GuestPhone::MULTIPLE_MESSAGE)],
                ['row' => 3, 'message' => 'phone "TBD": '.lcfirst(GuestPhone::CHARACTERS_MESSAGE)],
            ]);

        $this->assertFalse(GuestGroup::query()->where('event_id', $event->id)->exists());
    }

    public function test_the_api_import_reports_invalid_rows(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$owner->createToken('t')->plainTextToken)
            ->post("/api/v1/host/events/{$event->id}/guests/import", [
                'file' => UploadedFile::fake()->createWithContent('guests.csv', "name,email,phone\nBad,,12345\n"),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('invalid', 1)
            ->assertJsonPath('problems.0.row', 2);
    }

    public function test_the_group_link_refuses_a_badly_formatted_phone(): void
    {
        $event = Event::factory()->for(User::factory()->create())->published()->create(['rsvp_deadline' => null]);
        $group = GuestGroup::factory()->for($event)->create();
        $group->enableLink(3);

        $this->post(route('group-rsvp.store', ['token' => $group->fresh()->rsvp_token]), [
            'name' => 'Mwila Banda',
            'email' => 'mwila@example.test',
            'phone' => 'asdf',
            'attendee_count' => 1,
        ])->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('guests', ['email' => 'mwila@example.test']);
    }

    public function test_the_private_open_rsvp_refuses_a_badly_formatted_phone(): void
    {
        $event = Event::factory()->published()->create(['is_public' => false, 'rsvp_deadline' => null]);

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), [
            'name' => 'Short Number',
            'email' => 'short@example.test',
            'phone' => '097123456',
            'status' => 'accepted',
            'attendee_count' => 1,
        ])->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('guests', ['email' => 'short@example.test']);
    }
}
