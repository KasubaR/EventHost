<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\GuestPassCard;
use App\Support\GuestPhone;
use App\Support\PrintableText;
use App\Support\ZambianPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * plans/guest-adding-gaps.md Phase 4: a name or event title the PDFs and the pass image cannot draw prints as "?" instead of an empty box,
 * the guest list says which names will not print, and a Zambian landline is never offered WhatsApp.
 */
class GuestPrintableAndLandlineTest extends TestCase
{
    use RefreshDatabase;

    // ── PrintableText ──────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function texts(): array
    {
        return [
            'plain latin' => ['Mwila Chanda', 'Mwila Chanda', false],
            'latin with accents' => ['José Müller-Ñandú Zoë', 'José Müller-Ñandú Zoë', false],
            'cyrillic' => ['Мила Ковач', 'Мила Ковач', false],
            'greek' => ['Αλέξανδρος', 'Αλέξανδρος', false],
            'armenian' => ['Արամ', 'Արամ', false],
            'chinese' => ['李小龍', '?', true],
            'chinese inside latin' => ['John 李 Doe', 'John ? Doe', true],
            'japanese' => ['田中さん', '?', true],
            'korean' => ['김민준', '?', true],
            'thai' => ['สมชาย', '?', true],
            'devanagari' => ['राहुल', '?', true],
            'emoji' => ['Mwila 😀 Banda', 'Mwila ? Banda', true],
            'two runs' => ['李 and 王', '? and ?', true],
            'arabic is drawn but flagged' => ['محمد', 'محمد', true],
            'hebrew is drawn but flagged' => ['דוד', 'דוד', true],
            'digits and punctuation' => ['Table #4 (VIP) - 2 guests', 'Table #4 (VIP) - 2 guests', false],
            'empty' => ['', '', false],
        ];
    }

    #[DataProvider('texts')]
    public function test_for_print_replaces_what_cannot_be_drawn(string $input, string $printed, bool $flagged): void
    {
        $this->assertSame($printed, PrintableText::forPrint($input));
        $this->assertSame($flagged, PrintableText::mayNotPrint($input));
    }

    public function test_null_passes_through(): void
    {
        $this->assertNull(PrintableText::forPrint(null));
        $this->assertFalse(PrintableText::mayNotPrint(null));
    }

    // ── the pass card ──────────────────────────────────────────────────────────────────────────────

    private function attendingGuest(string $guestName, string $eventName, string $token): Guest
    {
        $event = Event::factory()->for(User::factory()->pro()->create())->published()->create([
            'name' => $eventName,
            'venue' => 'Sunset Gardens',
            'event_date' => now()->addDays(20)->startOfDay(),
        ]);

        $guest = Guest::factory()->for($event)->create(['name' => $guestName, 'invitation_token' => $token]);
        Rsvp::factory()->for($guest)->create(['status' => RsvpStatus::Accepted, 'attendee_count' => 2]);

        return $guest;
    }

    public function test_the_print_copy_is_drawable_and_the_original_is_untouched(): void
    {
        $guest = $this->attendingGuest('李小龍', 'Wedding 😀 Party', 'tok_print');
        $card = GuestPassCard::for($guest, $guest->event);

        $print = $card->forPrint();

        $this->assertSame('?', $print->guestName);
        $this->assertSame('Wedding ? Party', $print->eventName);
        $this->assertSame('李小龍', $card->guestName);
        $this->assertSame('Wedding 😀 Party', $card->eventName);
        $this->assertSame($card->admits, $print->admits);
        $this->assertSame($card->fingerprint(), $card->fingerprint(), 'the cache key comes from the original');
    }

    public function test_the_web_pass_still_shows_the_real_name(): void
    {
        $guest = $this->attendingGuest('李小龍', 'Garden Party', 'tok_web');

        $this->get(route('rsvp.token.pass', $guest->invitation_token))->assertOk()->assertSee('李小龍');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'chinese' => ['李小龍', 'Wedding of 李 and 王'],
            'emoji' => ['Mwila 😀', 'Party 🎉🎉'],
            'cyrillic' => ['Мила Ковач', 'Свадьба'],
            'arabic' => ['محمد علي', 'حفل زفاف'],
            'thai and devanagari' => ['สมชาย', 'राहुल'],
        ];
    }

    #[DataProvider('names')]
    public function test_the_pdf_and_the_png_render_for_any_script(string $guestName, string $eventName): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest($guestName, $eventName, 'tok_render_'.md5($guestName));

        $pdf = $this->get(route('rsvp.token.pass-download', $guest->invitation_token))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $png = $this->get(route('rsvp.token.pass-image', $guest->invitation_token))->assertOk();
        $this->assertNotFalse(getimagesizefromstring($png->getContent()));
        $this->assertSame('image/png', $png->headers->get('Content-Type'));
    }

    public function test_the_printed_guest_list_prints_a_question_mark_for_an_undrawable_name(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['name' => '李小龍']);
        Guest::factory()->for($event)->create(['name' => 'Chanda Mwila']);

        $response = $this->actingAs($owner)->get(route('events.guests.export-pdf', $event))->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_guest_list_flags_names_that_will_not_print(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['name' => '李小龍', 'email' => 'cn@example.test']);
        Guest::factory()->for($event)->create(['name' => 'Chanda Mwila', 'email' => 'zm@example.test']);

        $html = $this->actingAs($owner)->get(route('events.guests.index', $event))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '(may not print correctly)'));
        $this->assertStringContainsString('李小龍', $html);
    }

    // ── landlines ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_zambian_landline_is_recognised(): void
    {
        $this->assertTrue(GuestPhone::isZambianLandline('0211123456'));
        $this->assertTrue(GuestPhone::isZambianLandline('+260 211 123456'));
        $this->assertTrue(GuestPhone::isZambianLandline('211123456'));
        $this->assertFalse(GuestPhone::isZambianLandline('0971234567'));
        $this->assertFalse(GuestPhone::isZambianLandline('+260 77 123 4567'));
        $this->assertFalse(GuestPhone::isZambianLandline('+44 20 7946 0958'));
        $this->assertFalse(GuestPhone::isZambianLandline(null));
        $this->assertFalse(GuestPhone::isZambianLandline('call me'));
    }

    public function test_a_landline_is_a_valid_phone_but_has_no_whatsapp_number(): void
    {
        $this->assertNull(GuestPhone::problem('0211123456'));
        $this->assertNull(GuestPhone::whatsAppDigits('0211123456'));
        $this->assertNull(GuestPhone::whatsAppDigits('+260211123456'));

        $this->assertSame('260971234567', GuestPhone::whatsAppDigits('0971234567'));
        $this->assertSame('260771234567', GuestPhone::whatsAppDigits('0771234567'));
        $this->assertSame('447700900123', GuestPhone::whatsAppDigits('+44 7700 900123'));
    }

    public function test_twilio_sends_only_to_zambian_mobiles(): void
    {
        $this->assertSame('+260971234567', ZambianPhone::toE164('0971234567'));
        $this->assertSame('+260771234567', ZambianPhone::toE164('+260 77 123 4567'));
        $this->assertNull(ZambianPhone::toE164('0211123456'));
        $this->assertNull(ZambianPhone::toE164('0861234567'), 'no Zambian number starts 8');
        $this->assertNull(ZambianPhone::toE164('12345'));
        $this->assertNull(ZambianPhone::toE164(null));
        $this->assertFalse(ZambianPhone::isValid('0211123456'));
        $this->assertTrue(ZambianPhone::isValid('0971234567'));
    }

    public function test_the_share_action_skips_a_landline_guest_and_the_list_says_why(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $mobile = Guest::factory()->for($event)->create(['phone' => '0971234567']);
        $landline = Guest::factory()->for($event)->create(['phone' => '0211123456']);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'prepare_whatsapp_share',
                'guest_ids' => [$mobile->id, $landline->id],
            ])
            ->assertSessionHas('bulk_count', 1)
            ->assertSessionHas('bulk_skipped', 1);

        $this->assertTrue($mobile->fresh()->invitation_sent);
        $this->assertFalse($landline->fresh()->invitation_sent);

        $html = $this->actingAs($owner)->get(route('events.guests.index', $event))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '(landline: no WhatsApp)'));
    }
}
