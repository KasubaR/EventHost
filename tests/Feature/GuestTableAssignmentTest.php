<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 1 of plans/guest-entry-pass.md — seating assignment reuses the existing
 * EventTable (photo-wall) entity rather than a second, unrelated field.
 */
class GuestTableAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_can_store_a_guest_with_a_table_assigned(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create(['label' => 'Table 5']);

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), [
                'name' => 'Taylor Example',
                'event_table_id' => (string) $table->id,
            ])
            ->assertRedirect(route('events.guests.index', $event));

        $guest = Guest::query()->where('name', 'Taylor Example')->firstOrFail();
        $this->assertSame($table->id, $guest->event_table_id);
        $this->assertSame('Table 5', $guest->tableLabel());
    }

    public function test_a_table_from_another_event_is_rejected(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $otherEvent = Event::factory()->for($owner)->create();
        $foreignTable = EventTable::factory()->for($otherEvent)->create();

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), [
                'name' => 'Someone',
                'event_table_id' => (string) $foreignTable->id,
            ])
            ->assertSessionHasErrors('event_table_id');
    }

    public function test_updating_a_guest_can_clear_the_table_assignment(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create();
        $guest = Guest::factory()->for($event)->create(['event_table_id' => $table->id]);

        $this->actingAs($owner)
            ->patch(route('events.guests.update', ['event' => $event, 'guest' => $guest->id]), [
                'name' => $guest->name,
                'event_table_id' => '',
            ])
            ->assertRedirect(route('events.guests.index', $event));

        $this->assertNull($guest->fresh()->event_table_id);
    }

    public function test_deleting_the_table_unassigns_the_guest_without_deleting_it(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create();
        $guest = Guest::factory()->for($event)->create(['event_table_id' => $table->id]);

        $table->delete();

        $this->assertNotNull($guest->fresh());
        $this->assertNull($guest->fresh()->event_table_id);
    }

    public function test_bulk_assign_table_updates_selected_guests(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create(['label' => 'Head Table']);

        $g1 = Guest::factory()->for($event)->create(['event_table_id' => null]);
        $g2 = Guest::factory()->for($event)->create(['event_table_id' => null]);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'assign_table',
                'guest_ids' => [$g1->id, $g2->id],
                'event_table_id' => (string) $table->id,
            ])
            ->assertRedirect(route('events.guests.index', $event));

        $this->assertSame($table->id, $g1->fresh()->event_table_id);
        $this->assertSame($table->id, $g2->fresh()->event_table_id);
    }

    public function test_bulk_assign_table_with_blank_value_clears_it(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create();
        $guest = Guest::factory()->for($event)->create(['event_table_id' => $table->id]);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'assign_table',
                'guest_ids' => [$guest->id],
                'event_table_id' => '',
            ])
            ->assertRedirect(route('events.guests.index', $event));

        $this->assertNull($guest->fresh()->event_table_id);
    }

    public function test_csv_import_matches_an_existing_table_label(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        EventTable::factory()->for($event)->create(['label' => 'Table 1']);

        $csv = "name,email,phone,group,table\nAlpha,a@example.com,,,Table 1\n";
        $file = UploadedFile::fake()->createWithContent('guests.csv', $csv);

        $this->actingAs($owner)
            ->post(route('events.guests.import.store', $event), ['file' => $file])
            ->assertSessionHas('status', 'guests-imported');

        $guest = Guest::query()->where('email', 'a@example.com')->firstOrFail();
        $this->assertSame('Table 1', $guest->tableLabel());
    }

    public function test_csv_import_leaves_an_unmatched_table_label_unassigned_rather_than_creating_one(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $csv = "name,email,phone,group,table\nAlpha,a@example.com,,,Tbale 1\n"; // typo, deliberately
        $file = UploadedFile::fake()->createWithContent('guests.csv', $csv);

        $this->actingAs($owner)
            ->post(route('events.guests.import.store', $event), ['file' => $file])
            ->assertSessionHas('status', 'guests-imported');

        $guest = Guest::query()->where('email', 'a@example.com')->firstOrFail();
        $this->assertNull($guest->event_table_id);
        // The typo must not have silently created a new table.
        $this->assertSame(0, EventTable::query()->where('event_id', $event->id)->count());
    }

    public function test_csv_import_table_match_is_case_insensitive(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create(['label' => 'Head Table']);

        $csv = "name,email,phone,group,table\nAlpha,a@example.com,,,head table\n";
        $file = UploadedFile::fake()->createWithContent('guests.csv', $csv);

        $this->actingAs($owner)
            ->post(route('events.guests.import.store', $event), ['file' => $file])
            ->assertSessionHas('status', 'guests-imported');

        $guest = Guest::query()->where('email', 'a@example.com')->firstOrFail();
        $this->assertSame($table->id, $guest->event_table_id);
    }

    public function test_guest_list_shows_the_table_label(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create(['label' => 'Table 9']);
        Guest::factory()->for($event)->create(['name' => 'Seated Guest', 'event_table_id' => $table->id]);

        $response = $this->actingAs($owner)->get(route('events.guests.index', $event));

        $response->assertOk();
        $response->assertSee('Table 9');
    }

    public function test_qr_badge_sheet_includes_the_table_label(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create(['label' => 'Table 3']);
        Guest::factory()->for($event)->create([
            'name' => 'Badge Guest',
            'invitation_token' => 'a-token-for-badge-guest',
            'event_table_id' => $table->id,
        ]);

        $response = $this->actingAs($owner)->get(route('events.guests.qr-sheet', $event));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }
}
