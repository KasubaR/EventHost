<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Support\GuestPhone;
use App\Support\SpreadsheetCell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * plans/guest-adding-gaps.md Phases 1 and 2: guest exports cannot run a guest-typed formula and open correctly in Excel; a phone
 * is found however it is written, shown consistently, and a household may share one number when the host says so.
 */
class GuestExportAndPhoneTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->pro()->create();
        $this->event = Event::factory()->for($this->host)->create();
    }

    private function guest(array $attributes = []): Guest
    {
        return Guest::factory()->for($this->event)->create($attributes);
    }

    // ── Phase 1: SpreadsheetCell ───────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function cells(): array
    {
        return [
            'formula' => ['=1+1', "'=1+1"],
            'hyperlink' => ['=HYPERLINK("http://evil.test","x")', "'=HYPERLINK(\"http://evil.test\",\"x\")"],
            'plus call' => ['+cmd|calc', "'+cmd|calc"],
            'minus call' => ['-2+SUM(A1)', "'-2+SUM(A1)"],
            'at' => ['@SUM(1)', "'@SUM(1)"],
            'tab' => ["\t=1", "'\t=1"],
            'carriage return' => ["\r=1", "'\r=1"],
            'newline' => ["\n=1", "'\n=1"],
            'phone with plus' => ['+260 97 123 4567', '+260 97 123 4567'],
            'phone with brackets' => ['+44 (0) 7700 900123', '+44 (0) 7700 900123'],
            'negative number' => ['-5', '-5'],
            'plain name' => ['Mwila Chanda', 'Mwila Chanda'],
            'apostrophe name' => ["O'Brien", "O'Brien"],
            'hyphenated name' => ['Chanda-Banda', 'Chanda-Banda'],
            'name starting with a letter after a dash' => ['-Mwila', "'-Mwila"],
            'empty' => ['', ''],
            'int' => [3, 3],
            'null' => [null, null],
        ];
    }

    #[DataProvider('cells')]
    public function test_a_cell_that_could_run_as_a_formula_is_neutralised(mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, SpreadsheetCell::safe($value));
    }

    public function test_the_guest_export_escapes_what_a_guest_typed_and_opens_as_utf8(): void
    {
        $guest = $this->guest(['name' => '=HYPERLINK("http://evil.test","click")', 'phone' => '+44 7700 900123']);
        $this->guest(['name' => 'Мила Ковач', 'email' => 'mila@example.test']);
        $this->guest(['name' => 'Chanda-Banda']);
        $guest->rsvp()->create([
            'event_id' => $this->event->id,
            'status' => 'accepted',
            'attendee_count' => 1,
            'message' => '@SUM(1+1)',
        ]);

        $csv = $this->actingAs($this->host)->get(route('events.guests.export', $this->event))->assertOk()->streamedContent();

        $this->assertStringStartsWith(SpreadsheetCell::BOM, $csv);
        $rows = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));

        $names = array_column($rows, 0);
        $this->assertContains("'=HYPERLINK(\"http://evil.test\",\"click\")", $names);
        $this->assertNotContains('=HYPERLINK("http://evil.test","click")', $names);
        $this->assertContains('Мила Ковач', $names);
        $this->assertContains('Chanda-Banda', $names);

        $formulaRow = collect($rows)->first(fn ($r) => str_starts_with((string) ($r[0] ?? ''), "'=HYPERLINK"));
        $this->assertSame("'@SUM(1+1)", $formulaRow[6]);
        $this->assertSame('+447700900123', $formulaRow[2], 'a phone with a plus stays a phone');
    }

    public function test_the_api_export_is_safe_too(): void
    {
        $this->guest(['name' => '+cmd|calc', 'phone' => '0971234567']);
        Sanctum::actingAs($this->host);

        $csv = $this->get(route('api.v1.host.events.guests.export', $this->event))->assertOk()->streamedContent();

        $this->assertStringStartsWith(SpreadsheetCell::BOM, $csv);
        $this->assertStringContainsString("'+cmd|calc", $csv);
        $this->assertStringContainsString('097 123 4567', $csv);
    }

    public function test_the_ticket_and_contribution_exports_are_safe(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/EventTicketManagementController.php'))
            .file_get_contents(base_path('app/Http/Controllers/Api/V1/EventTicketManagementController.php'))
            .file_get_contents(base_path('app/Http/Controllers/Admin/ContributionRevenueController.php'));

        $this->assertSame(3, substr_count($source, 'SpreadsheetCell::row('));
        $this->assertSame(3, substr_count($source, 'SpreadsheetCell::BOM'));
    }

    // ── Phase 2: GuestPhone ────────────────────────────────────────────────────────────────────────

    public function test_a_bracketed_trunk_zero_does_not_change_the_number(): void
    {
        $this->assertSame(GuestPhone::key('+447700900123'), GuestPhone::key('+44 (0) 7700 900123'));
        $this->assertSame(GuestPhone::key('+260971234567'), GuestPhone::key('+260 (0) 97 123 4567'));
        $this->assertNull(GuestPhone::problem('+44 (0) 7700 900123'));
    }

    public function test_display_formats_a_stored_number_without_rewriting_it(): void
    {
        $this->assertSame('097 123 4567', GuestPhone::display('0971234567'));
        $this->assertSame('097 123 4567', GuestPhone::display('+260 97 123 4567'));
        $this->assertSame('097 123 4567', GuestPhone::display('971234567'));
        $this->assertSame('+447700900123', GuestPhone::display('+44 7700 900123'));
        $this->assertSame('+447700900123', GuestPhone::display('0044 7700 900123'));
        $this->assertSame('call me later', GuestPhone::display(' call me later '), 'a legacy value is shown as stored');
        $this->assertNull(GuestPhone::display(null));
        $this->assertNull(GuestPhone::display('  '));

        $guest = $this->guest(['phone' => '+260 97 123 4567']);
        $this->assertSame('+260 97 123 4567', $guest->fresh()->phone);
    }

    public function test_the_list_shows_the_display_form_and_the_api_adds_it_beside_the_stored_one(): void
    {
        $guest = $this->guest(['name' => 'Display Guest', 'phone' => '+260971234567']);

        $this->actingAs($this->host)->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertSee('097 123 4567');

        Sanctum::actingAs($this->host);
        $this->getJson(route('api.v1.host.events.guests.index', $this->event))
            ->assertOk()
            ->assertJsonFragment(['phone' => '+260971234567', 'phone_display' => '097 123 4567']);
    }

    public function test_search_finds_a_phone_however_it_was_typed_or_stored(): void
    {
        $this->guest(['name' => 'Zambian Plus', 'phone' => '+260 97 123 4567']);
        $this->guest(['name' => 'Zambian Local', 'phone' => '0961112222']);
        $this->guest(['name' => 'Foreigner', 'phone' => '+44 7700 900123']);
        $this->guest(['name' => 'Nobody Phone', 'phone' => null]);

        $search = fn (string $q) => $this->actingAs($this->host)->get(route('events.guests.index', ['event' => $this->event, 'q' => $q]));

        $search('0971234567')->assertSee('Zambian Plus')->assertDontSee('Zambian Local');
        $search('+260971234567')->assertSee('Zambian Plus');
        $search('097 123 4567')->assertSee('Zambian Plus');
        $search('971234567')->assertSee('Zambian Plus');
        $search('096-111-2222')->assertSee('Zambian Local')->assertDontSee('Zambian Plus');
        $search('7700900123')->assertSee('Foreigner');
        $search('Foreigner')->assertSee('Foreigner')->assertDontSee('Zambian Plus');
    }

    public function test_a_short_number_like_term_is_not_treated_as_a_phone(): void
    {
        $this->assertNull(GuestPhone::searchNeedle('097'));
        $this->assertNull(GuestPhone::searchNeedle('Mwila'));
        $this->assertSame('971234567', GuestPhone::searchNeedle('0971234567'));
        $this->assertSame('971234567', GuestPhone::searchNeedle('+260 97 123 4567'));
    }

    public function test_the_duplicate_check_still_finds_every_spelling(): void
    {
        $existing = $this->guest(['phone' => '+260 (0) 97 123 4567']);

        foreach (['0971234567', '+260971234567', '971234567', '097-123-4567'] as $typed) {
            $this->assertSame($existing->id, Guest::matchingPhone($this->event, $typed)?->id, $typed);
        }

        $this->assertNull(Guest::matchingPhone($this->event, '0961112222'));
        $this->assertNull(Guest::matchingPhone($this->event, '+44 7971234567'), 'a foreign number sharing the tail is a different number');
    }

    // ── Phase 2: shared numbers ────────────────────────────────────────────────────────────────────

    public function test_a_shared_number_is_refused_unless_the_host_ticks_the_box(): void
    {
        $first = $this->guest(['name' => 'Mum Banda', 'phone' => '0971234567']);

        $this->actingAs($this->host)
            ->post(route('events.guests.store', $this->event), ['name' => 'Dad Banda', 'phone' => '+260 97 123 4567'])
            ->assertSessionHasErrors('phone');
        $this->assertSame(1, Guest::query()->where('event_id', $this->event->id)->count());

        $this->actingAs($this->host)
            ->post(route('events.guests.store', $this->event), ['name' => 'Dad Banda', 'phone' => '+260 97 123 4567', 'allow_shared_phone' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('events.guests.index', $this->event))
            ->assertSessionHas('guest_shared_phone', 'Mum Banda');

        $this->assertSame(2, Guest::query()->where('event_id', $this->event->id)->count());
        $this->assertNotNull($first->fresh());
    }

    public function test_the_ticked_box_does_not_flash_when_the_number_is_not_shared(): void
    {
        $this->actingAs($this->host)
            ->post(route('events.guests.store', $this->event), ['name' => 'Solo Guest', 'phone' => '0971234567', 'allow_shared_phone' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('guest_shared_phone');
    }

    public function test_the_flash_is_shown_on_the_list(): void
    {
        $this->guest(['name' => 'Mum Banda', 'phone' => '0971234567']);

        $this->actingAs($this->host)
            ->followingRedirects()
            ->post(route('events.guests.store', $this->event), ['name' => 'Dad Banda', 'phone' => '0971234567', 'allow_shared_phone' => '1'])
            ->assertSee('This number is shared with')
            ->assertSee('Mum Banda');
    }

    public function test_a_guest_who_already_shares_a_number_stays_editable_and_the_page_says_so(): void
    {
        $this->guest(['name' => 'Mum Banda', 'phone' => '0971234567']);
        $dad = $this->guest(['name' => 'Dad Banda', 'phone' => '0971234567']);

        $this->actingAs($this->host)
            ->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $dad]))
            ->assertOk()
            ->assertSee('Shares this number with')
            ->assertSee('Mum Banda');

        $this->actingAs($this->host)
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $dad]), ['name' => 'Dad B. Banda', 'phone' => '0971234567'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Dad B. Banda', $dad->fresh()->name);
    }

    public function test_changing_a_guest_onto_someone_elses_number_needs_the_tick(): void
    {
        $this->guest(['name' => 'Mum Banda', 'phone' => '0971234567']);
        $other = $this->guest(['name' => 'Uncle Banda', 'phone' => '0961112222']);

        $update = fn (array $extra = []) => $this->actingAs($this->host)
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $other]), array_merge(['name' => 'Uncle Banda', 'phone' => '+260 97 123 4567'], $extra));

        $update()->assertSessionHasErrors('phone');
        $this->assertSame('0961112222', $other->fresh()->phone);

        $update(['allow_shared_phone' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('+260 97 123 4567', $other->fresh()->phone);
    }

    public function test_the_api_honours_the_tick_and_otherwise_refuses(): void
    {
        $this->guest(['name' => 'Mum Banda', 'phone' => '0971234567']);
        Sanctum::actingAs($this->host);

        $this->postJson(route('api.v1.host.events.guests.store', $this->event), ['name' => 'Dad Banda', 'phone' => '0971234567'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->postJson(route('api.v1.host.events.guests.store', $this->event), ['name' => 'Dad Banda', 'phone' => '0971234567', 'allow_shared_phone' => true])
            ->assertCreated();
    }

    public function test_the_public_forms_never_offer_the_override(): void
    {
        $event = Event::factory()->for($this->host)->publicAudience()->create(['rsvp_deadline' => null]);
        Guest::factory()->for($event)->create(['name' => 'On List', 'email' => 'first@example.test', 'phone' => '0971234567']);

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), [
            'name' => 'Stranger', 'email' => 'stranger@example.test', 'phone' => '0971234567',
            'status' => 'accepted', 'attendee_count' => 1, 'allow_shared_phone' => '1',
        ]);

        $this->assertSame(0, Guest::query()->where('email', 'stranger@example.test')->count());
    }
}
