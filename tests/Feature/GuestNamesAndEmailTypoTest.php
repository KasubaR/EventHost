<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Support\EmailTypo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * plans/guest-adding-gaps.md Phase 3: a name made of invisible characters is "required" again and bidirectional controls are stripped;
 * an email one slip away from a common provider gets a "did you mean" and is still saved as typed.
 */
class GuestNamesAndEmailTypoTest extends TestCase
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

    // ── names ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function names(): array
    {
        return [
            'zero-width space inside' => ["Mwi\u{200B}la Chanda", 'Mwila Chanda'],
            'zero-width joiner and non-joiner' => ["A\u{200D}b\u{200C}c", 'Abc'],
            'word joiner and BOM' => ["\u{FEFF}Mwila\u{2060}", 'Mwila'],
            'bidi override' => ["\u{202E}adnahC", 'adnahC'],
            'bidi isolates' => ["\u{2066}Chanda\u{2069}", 'Chanda'],
            'only zero-width' => ["\u{200B}\u{200B}\u{200D}", null],
            'only a bidi override' => ["\u{202E}", null],
            'zero-width between spaces' => [" \u{200B} ", null],
            'control characters' => ["Mwila\x00\x1B Chanda", 'Mwila Chanda'],
            'ordinary non-Latin kept' => ['Мила  Ковач', 'Мила Ковач'],
            'arabic kept' => ['  محمد  علي ', 'محمد علي'],
            'right-to-left mark kept' => ["Chanda\u{200F}", "Chanda\u{200F}"],
            'accent normalised' => ["Jose\u{0301}", 'José'],
        ];
    }

    #[DataProvider('names')]
    public function test_clean_name_drops_invisible_and_bidi_controls(string $input, ?string $expected): void
    {
        $this->assertSame($expected, Guest::cleanName($input));
    }

    public function test_a_name_of_only_invisible_characters_is_refused_as_missing(): void
    {
        $this->actingAs($this->host)
            ->post(route('events.guests.store', $this->event), ['name' => "\u{200B}\u{200D}", 'email' => 'blank@example.test'])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Guest::query()->where('event_id', $this->event->id)->count());
    }

    public function test_a_bidi_override_never_reaches_the_database(): void
    {
        $this->actingAs($this->host)
            ->post(route('events.guests.store', $this->event), ['name' => "\u{202E}adnahC alIwM", 'email' => 'bidi@example.test'])
            ->assertSessionHasNoErrors();

        $this->assertSame('adnahC alIwM', Guest::query()->where('email', 'bidi@example.test')->value('name'));
    }

    public function test_the_open_rsvp_form_cleans_the_name_the_same_way(): void
    {
        $event = Event::factory()->for($this->host)->published()->publicAudience()->create(['rsvp_deadline' => null]);

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), [
            'name' => "\u{200B}\u{200B}", 'email' => 'zw@example.test', 'phone' => '0971234567', 'status' => 'accepted', 'attendee_count' => 1,
        ])->assertSessionHasErrors('name');

        $this->assertSame(0, Guest::query()->where('email', 'zw@example.test')->count());
    }

    // ── email typos ────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function emails(): array
    {
        return [
            'transposed gmail' => ['john@gmial.com', 'john@gmail.com'],
            'no ending' => ['john@gmail', 'john@gmail.com'],
            'wrong ending' => ['john@gmail.co', 'john@gmail.com'],
            'gmail.cm' => ['john@gmail.cm', 'john@gmail.com'],
            'hotmail missing letter' => ['john@hotmal.com', 'john@hotmail.com'],
            'yahoo double' => ['john@yahooo.com', 'john@yahoo.com'],
            'outlook typo' => ['john@outlok.com', 'john@outlook.com'],
            'icloud typo' => ['john@iclod.com', 'john@icloud.com'],
            'upper case' => ['John@GMIAL.COM', 'john@gmail.com'],
            'correct gmail' => ['john@gmail.com', null],
            'real yahoo uk' => ['john@yahoo.co.uk', null],
            'real hotmail uk' => ['john@hotmail.co.uk', null],
            'live.com is real' => ['john@live.com', null],
            'life.com is another company' => ['john@life.com', null],
            'a company domain' => ['john@kinpinarts.com', null],
            'a far-off domain' => ['john@gmailer.org', null],
            'no at sign' => ['johngmail.com', null],
            'two at signs' => ['a@b@gmail.com', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('emails')]
    public function test_the_suggestion_is_only_for_a_near_miss_of_a_common_provider(string $email, ?string $expected): void
    {
        $this->assertSame($expected, EmailTypo::suggest($email));
    }

    public function test_null_has_no_suggestion(): void
    {
        $this->assertNull(EmailTypo::suggest(null));
    }

    public function test_adding_a_guest_with_a_typo_saves_it_as_typed_and_offers_the_fix(): void
    {
        $response = $this->actingAs($this->host)
            ->post(route('events.guests.store', $this->event), ['name' => 'Typo Guest', 'email' => 'typo@gmial.com'])
            ->assertSessionHasNoErrors();

        $guest = Guest::query()->where('event_id', $this->event->id)->firstOrFail();
        $this->assertSame('typo@gmial.com', $guest->email);

        $response->assertSessionHas('email_typo', fn (array $t) => $t['suggestion'] === 'typo@gmail.com'
            && $t['guest'] === 'Typo Guest'
            && str_contains($t['url'], 'email=typo%40gmail.com'));
    }

    public function test_the_list_shows_the_suggestion_with_a_link_to_fix_it(): void
    {
        $this->actingAs($this->host)
            ->followingRedirects()
            ->post(route('events.guests.store', $this->event), ['name' => 'Typo Guest', 'email' => 'typo@gmial.com'])
            ->assertSee('Did you mean')
            ->assertSee('typo@gmail.com')
            ->assertSee('Use that address');
    }

    public function test_the_edit_page_is_prefilled_with_the_fix_but_nothing_is_saved_until_the_host_saves(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['email' => 'typo@gmial.com']);

        $this->actingAs($this->host)
            ->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest, 'email' => 'typo@gmail.com']))
            ->assertOk()
            ->assertSee('value="typo@gmail.com"', false);

        $this->assertSame('typo@gmial.com', $guest->fresh()->email);

        $this->actingAs($this->host)
            ->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest]))
            ->assertSee('value="typo@gmial.com"', false);
    }

    public function test_a_prefill_from_the_url_is_escaped(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['email' => 'ok@example.test']);

        $this->actingAs($this->host)
            ->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest, 'email' => '"><script>alert(1)</script>']))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_editing_to_a_typo_offers_the_fix_and_a_correct_address_says_nothing(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['name' => 'Edit Guest', 'email' => 'fine@example.test']);

        $this->actingAs($this->host)
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $guest]), ['name' => 'Edit Guest', 'email' => 'edit@hotmal.com'])
            ->assertSessionHas('email_typo', fn (array $t) => $t['suggestion'] === 'edit@hotmail.com');

        $this->actingAs($this->host)
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $guest]), ['name' => 'Edit Guest', 'email' => 'edit@hotmail.com'])
            ->assertSessionMissing('email_typo');
    }

    public function test_a_real_address_never_flashes(): void
    {
        $this->actingAs($this->host)
            ->post(route('events.guests.store', $this->event), ['name' => 'Real Guest', 'email' => 'real@kinpinarts.com'])
            ->assertSessionMissing('email_typo');
    }

    public function test_the_api_reports_a_suggestion_without_blocking_the_save(): void
    {
        Sanctum::actingAs($this->host);

        $this->postJson(route('api.v1.host.events.guests.store', $this->event), ['name' => 'Api Typo', 'email' => 'api@gmial.com'])
            ->assertCreated()
            ->assertJsonPath('email', 'api@gmial.com')
            ->assertJsonPath('email_suggestion', 'api@gmail.com');

        $this->postJson(route('api.v1.host.events.guests.store', $this->event), ['name' => 'Api Fine', 'email' => 'api@gmail.com'])
            ->assertCreated()
            ->assertJsonPath('email_suggestion', null);
    }
}
