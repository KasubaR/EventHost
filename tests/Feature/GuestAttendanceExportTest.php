<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 of plans/guest-entry-pass.md — downloading who actually attended, not
 * just who was invited or who RSVP'd.
 */
class GuestAttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_to_checked_in_guests_only(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        Guest::factory()->for($event)->create(['name' => 'Arrived Guest', 'checked_in_at' => now()]);
        Guest::factory()->for($event)->create(['name' => 'No Show Guest', 'checked_in_at' => null]);

        $response = $this->actingAs($owner)
            ->get(route('events.guests.index', ['event' => $event, 'checked_in' => 'yes']));

        $response->assertOk();
        $response->assertSee('Arrived Guest');
        $response->assertDontSee('No Show Guest');
    }

    public function test_index_filters_to_not_checked_in_guests(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        Guest::factory()->for($event)->create(['name' => 'Arrived Guest', 'checked_in_at' => now()]);
        Guest::factory()->for($event)->create(['name' => 'No Show Guest', 'checked_in_at' => null]);

        $response = $this->actingAs($owner)
            ->get(route('events.guests.index', ['event' => $event, 'checked_in' => 'no']));

        $response->assertOk();
        $response->assertSee('No Show Guest');
        $response->assertDontSee('Arrived Guest');
    }

    public function test_csv_export_includes_checked_in_at_and_checked_in_by(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create(['name' => 'Door Staffer']);
        $event = Event::factory()->for($owner)->create();

        Guest::factory()->for($event)->create([
            'name' => 'Arrived Guest',
            'checked_in_at' => now(),
            'checked_in_by' => $staffer->id,
        ]);
        Guest::factory()->for($event)->create(['name' => 'No Show Guest']);

        $response = $this->actingAs($owner)->get(route('events.guests.export', $event));
        $response->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Checked In At', $csv);
        $this->assertStringContainsString('Checked In By', $csv);
        $this->assertStringContainsString('Arrived Guest', $csv);
        $this->assertStringContainsString('Door Staffer', $csv);

        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $noShowRow = collect($lines)->first(fn ($row) => ($row[0] ?? null) === 'No Show Guest');
        $this->assertNotNull($noShowRow);
        $this->assertSame('', $noShowRow[12] ?? null); // Checked In At
        $this->assertSame('', $noShowRow[13] ?? null); // Checked In By
    }

    public function test_csv_export_leaves_checked_in_by_blank_for_a_staff_link_scan(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        // CheckInService::confirm() passes a null staff id for the passwordless
        // door-staff-link path — this is not missing data.
        Guest::factory()->for($event)->create([
            'name' => 'Staff Link Guest',
            'checked_in_at' => now(),
            'checked_in_by' => null,
        ]);

        $csv = $this->actingAs($owner)->get(route('events.guests.export', $event))->streamedContent();

        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $row = collect($lines)->first(fn ($r) => ($r[0] ?? null) === 'Staff Link Guest');
        $this->assertNotSame('', $row[12] ?? null); // Checked In At is present
        $this->assertSame('', $row[13] ?? null); // Checked In By is blank, not an error
    }

    public function test_csv_export_respects_the_checked_in_filter(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        Guest::factory()->for($event)->create(['name' => 'Arrived Guest', 'checked_in_at' => now()]);
        Guest::factory()->for($event)->create(['name' => 'No Show Guest']);

        $csv = $this->actingAs($owner)
            ->get(route('events.guests.export', ['event' => $event, 'checked_in' => 'yes']))
            ->streamedContent();

        $this->assertStringContainsString('Arrived Guest', $csv);
        $this->assertStringNotContainsString('No Show Guest', $csv);
    }

    public function test_pdf_export_includes_checked_in_column_and_respects_the_filter(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        Guest::factory()->for($event)->create(['name' => 'Arrived Guest', 'checked_in_at' => now()]);
        Guest::factory()->for($event)->create(['name' => 'No Show Guest']);

        $response = $this->actingAs($owner)
            ->get(route('events.guests.export-pdf', ['event' => $event, 'checked_in' => 'yes']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_download_attendee_list_button_is_on_the_scanner_page_and_prefiltered(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();

        $response = $this->actingAs($owner)->get(route('events.checkin.scan', $event));

        $response->assertOk();
        $response->assertSee(route('events.guests.export', ['event' => $event, 'checked_in' => 'yes']), false);
    }

    public function test_bulk_action_redirect_preserves_the_checked_in_filter(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['checked_in_at' => now()]);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'mark_sent',
                'guest_ids' => [$guest->id],
                'checked_in' => 'yes',
            ])
            ->assertRedirect(route('events.guests.index', ['event' => $event, 'checked_in' => 'yes']));
    }
}
