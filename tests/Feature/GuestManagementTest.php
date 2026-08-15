<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\User;
use App\Support\WhatsAppInviteLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class GuestManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_owner_cannot_view_guest_list(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->get(route('events.guests.index', $event))
            ->assertForbidden();
    }

    public function test_host_can_store_guest_with_group_and_mark_invitation_sent(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $group = GuestGroup::factory()->for($event)->create(['name' => 'VIP']);

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), [
                'name' => 'Taylor Example',
                'email' => 'taylor@example.com',
                'phone' => '+260971234567',
                'guest_group_id' => (string) $group->id,
                'plus_one_allowed' => '0',
                'mark_invitation_sent' => '1',
            ])
            ->assertRedirect(route('events.guests.index', $event));

        $guest = Guest::query()->where('email', 'taylor@example.com')->first();
        $this->assertNotNull($guest);
        $this->assertSame($group->id, $guest->guest_group_id);
        $this->assertTrue($guest->invitation_sent);
        $this->assertNotNull($guest->invitation_sent_at);
        $this->assertNotNull($guest->invitation_token);
    }

    public function test_guest_index_search_filters_by_query(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        Guest::factory()->for($event)->create(['name' => 'Alice Wonder', 'email' => 'alice@example.com']);
        Guest::factory()->for($event)->create(['name' => 'Bob Builder', 'email' => 'bob@example.com']);

        $response = $this->actingAs($owner)
            ->get(route('events.guests.index', ['event' => $event, 'q' => 'alice']));

        $response->assertOk();
        $response->assertSee('Alice Wonder');
        $response->assertDontSee('Bob Builder');
    }

    public function test_guest_index_filters_invitation_sent(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        Guest::factory()->for($event)->create([
            'name' => 'Marked Guest',
            'invitation_sent' => true,
            'invitation_sent_at' => now(),
        ]);
        Guest::factory()->for($event)->create([
            'name' => 'Unmarked Guest',
            'invitation_sent' => false,
            'invitation_sent_at' => null,
        ]);

        $response = $this->actingAs($owner)
            ->get(route('events.guests.index', ['event' => $event, 'invitation_sent' => 'yes']));

        $response->assertOk();
        $response->assertSee('Marked Guest');
        $response->assertDontSee('Unmarked Guest');
    }

    public function test_guest_row_actions_are_in_a_more_menu(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create([
            'name' => 'Menu Guest',
            'phone' => '+260971112233',
        ]);

        $this->actingAs($owner)
            ->get(route('events.guests.index', $event))
            ->assertOk()
            ->assertSee('data-evt-more', escape: false)
            ->assertSee('More actions for Menu Guest', escape: false)
            ->assertSee('Copy link', escape: false)
            ->assertSee('WhatsApp', escape: false)
            ->assertSee('QR check-in', escape: false)
            ->assertSee(route('events.guests.qr', ['event' => $event, 'guest' => $guest->id]), escape: false)
            ->assertSee('Mark sent', escape: false)
            ->assertSee(route('events.guests.edit', ['event' => $event, 'guest' => $guest->id]), escape: false)
            ->assertSee('Remove', escape: false);
    }

    /**
     * Regression: routes/web.php's guests resource previously called
     * ->scoped(['guest' => 'guests']) — Route::resource::scoped() takes {param:
     * COLUMN} pairs, not relation names, so that told Laravel to match a column
     * literally named "guests" (which doesn't exist) instead of the default 'id'.
     * Every edit/update/destroy 404'd for every guest, silently, since ownership
     * is enforced separately by GuestPolicy and never surfaced the mismatch.
     */
    public function test_host_can_edit_update_and_delete_a_guest(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['name' => 'Original Name']);

        $this->actingAs($owner)
            ->get(route('events.guests.edit', ['event' => $event, 'guest' => $guest->id]))
            ->assertOk();

        $this->actingAs($owner)
            ->patch(route('events.guests.update', ['event' => $event, 'guest' => $guest->id]), [
                'name' => 'Updated Name',
            ])
            ->assertRedirect(route('events.guests.index', $event));

        $this->assertSame('Updated Name', $guest->fresh()->name);

        $this->actingAs($owner)
            ->delete(route('events.guests.destroy', ['event' => $event, 'guest' => $guest->id]))
            ->assertRedirect(route('events.guests.index', $event));

        $this->assertDatabaseMissing('guests', ['id' => $guest->id]);
    }

    public function test_host_can_manage_guest_groups(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('events.guest-groups.store', $event), ['name' => 'Family'])
            ->assertRedirect(route('events.guest-groups.index', $event));

        $group = GuestGroup::query()->where('event_id', $event->id)->where('name', 'Family')->first();
        $this->assertNotNull($group);

        $this->actingAs($owner)
            ->patch(route('events.guest-groups.update', ['event' => $event, 'guest_group' => $group->id]), ['name' => 'Extended Family'])
            ->assertRedirect(route('events.guest-groups.index', $event));

        $group->refresh();
        $this->assertSame('Extended Family', $group->name);

        $this->actingAs($owner)
            ->delete(route('events.guest-groups.destroy', ['event' => $event, 'guest_group' => $group->id]))
            ->assertRedirect(route('events.guest-groups.index', $event));

        $this->assertDatabaseMissing('guest_groups', ['id' => $group->id]);
    }

    public function test_csv_import_creates_guests_skips_duplicate_email_and_creates_groups(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $csv = "name,email,phone,group\nAlpha,a@example.com,,Friends\nBeta,b@example.com,,Friends\n";
        $file = UploadedFile::fake()->createWithContent('guests.csv', $csv);

        $this->actingAs($owner)
            ->post(route('events.guests.import.store', $event), ['file' => $file])
            ->assertRedirect(route('events.guests.index', $event))
            ->assertSessionHas('status', 'guests-imported');

        $this->assertSame(2, $event->guests()->count());
        $this->assertDatabaseHas('guest_groups', ['event_id' => $event->id, 'name' => 'Friends']);

        $dupCsv = "name,email,phone,group\nGamma,a@example.com,,Friends\n";
        $dupFile = UploadedFile::fake()->createWithContent('guests-dup.csv', $dupCsv);

        $response = $this->actingAs($owner)
            ->post(route('events.guests.import.store', $event), ['file' => $dupFile]);

        $response->assertSessionHas('status', 'guests-imported');
        $response->assertSessionHas('import_created', 0);
        $response->assertSessionHas('import_skipped', 1);

        $this->assertSame(2, Guest::query()->where('event_id', $event->id)->count());
    }

    public function test_bulk_assign_group_updates_selected_guests(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $group = GuestGroup::factory()->for($event)->create(['name' => 'Work']);

        $g1 = Guest::factory()->for($event)->create(['guest_group_id' => null]);
        $g2 = Guest::factory()->for($event)->create(['guest_group_id' => null]);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'assign_group',
                'guest_ids' => [$g1->id, $g2->id],
                'guest_group_id' => (string) $group->id,
            ])
            ->assertRedirect(route('events.guests.index', $event));

        $this->assertSame($group->id, $g1->fresh()->guest_group_id);
        $this->assertSame($group->id, $g2->fresh()->guest_group_id);
    }

    public function test_whatsapp_invite_link_strips_non_digits(): void
    {
        $url = WhatsAppInviteLink::url('+260 97 111 2233', 'Hello');

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://wa.me/260971112233', $url);
        $this->assertStringContainsString(rawurlencode('Hello'), $url);
    }
}
