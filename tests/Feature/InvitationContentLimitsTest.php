<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\User;
use App\Notifications\EventUpdatedNotification;
use App\Support\EventIcsDocument;
use App\Support\EventPlace;
use App\Support\InvitationDescriptionFallback;
use App\Support\InvitationDesignNotices;
use App\Support\InvitationNames;
use App\Support\InvitationRsvpState;
use App\Support\InvitationTemplateNotices;
use App\Support\InvitationTextLength;
use App\Support\ShortText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan: plans/invitation-page-content-limits.md. A very long name or venue, no description, no location, and RSVP not available
 * must neither break a guest page nor leave a guest guessing.
 */
class InvitationContentLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $attributes = []): Event
    {
        static $n = 0;
        $n++;

        return Event::factory()->for(User::factory()->create())->published()->create(array_merge([
            'slug' => 'limits-'.$n,
            'rsvp_deadline' => null,
            'event_date' => Event::venueToday()->addDays(30)->toDateString(),
            'host_contact_phone' => '0977 123 456',
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function noPlace(): array
    {
        return ['venue' => null, 'location_name' => null, 'formatted_address' => null, 'latitude' => null, 'longitude' => null];
    }

    // ── Phase 1: long text ──

    public function test_a_255_character_unbroken_name_and_venue_render_on_every_layout(): void
    {
        $solid = str_repeat('Supercalifragilistic', 12);
        $templates = InvitationTemplate::query()->get();
        $this->assertGreaterThanOrEqual(10, $templates->count());

        foreach ($templates as $template) {
            $event = $this->event([
                'name' => $solid,
                'venue' => $solid,
                'location_name' => $solid,
                'invitation_template_id' => $template->id,
            ]);

            $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

            $this->assertStringContainsString('evt-name--xlong', $html, "{$template->slug} marks a very long name");
            $this->assertStringContainsString($solid, $html, "{$template->slug} still prints the whole name");
        }
    }

    public function test_the_length_classes_step_at_forty_and_eighty_characters(): void
    {
        $this->assertSame('', InvitationTextLength::nameClass(str_repeat('a', 40)));
        $this->assertSame('evt-name--long', InvitationTextLength::nameClass(str_repeat('a', 41)));
        $this->assertSame('evt-name--long', InvitationTextLength::nameClass(str_repeat('a', 80)));
        $this->assertSame('evt-name--xlong', InvitationTextLength::nameClass(str_repeat('a', 81)));
        $this->assertSame('', InvitationTextLength::nameClass(null));
    }

    public function test_the_guest_stylesheets_wrap_long_text(): void
    {
        $invitation = file_get_contents(public_path('css/events-invitation.css'));
        $this->assertMatchesRegularExpression('/\.evt-invitation\s*\{[^}]*overflow-wrap:\s*break-word;\s*overflow-wrap:\s*anywhere;/s', $invitation);
        $this->assertStringContainsString('.evt-invitation.evt-name--xlong h1', $invitation);
        $this->assertStringContainsString('.evt-invitation.evt-name--long h1', $invitation);

        foreach (['rsvp-public.css' => '.rsvp-page {', 'events-public.css' => '.evt-status-page,', 'ticket-checkout.css' => '.tkc-page {', 'ticket-event-public.css' => '.tev-wrap {'] as $file => $selector) {
            $css = file_get_contents(public_path('css/'.$file));
            $this->assertStringContainsString($selector, $css);
            $this->assertStringContainsString('overflow-wrap: anywhere;', $css, $file);
        }
    }

    // ── Phase 2: names and host guidance ──

    public function test_only_a_short_name_is_split_into_two_names(): void
    {
        $this->assertSame(['Kasuba', 'Tamara'], InvitationNames::split('Kasuba & Tamara'));
        $long = 'Peter Banda and Bwanga Chibaye Mulenga Phiri Zulu Kapata Zimba';
        $this->assertSame([$long, ''], InvitationNames::split($long));
        $manyWords = 'One Two Three Four Five and Six';
        $this->assertSame([$manyWords, ''], InvitationNames::split($manyWords));
    }

    public function test_the_event_form_warns_softly_about_a_long_name_and_venue(): void
    {
        $form = file_get_contents(resource_path('views/events/partials/form-fields.blade.php'));
        $this->assertStringContainsString('data-length-hint-for="name" data-length-at="70"', $form);
        $this->assertStringContainsString('data-length-hint-for="venue" data-length-at="80"', $form);
        $this->assertStringContainsString('maxlength="255"', $form, 'a warning, not a lower limit');
        $this->assertStringContainsString('data-length-hint-for', file_get_contents(public_path('js/events-form.js')));
    }

    public function test_the_host_is_told_about_a_very_long_name(): void
    {
        $long = $this->event(['name' => str_repeat('a', 81)]);
        $short = $this->event(['name' => 'A fine name']);

        $this->assertStringContainsString('81 characters', collect(InvitationDesignNotices::for($long))->pluck('message')->implode(' '));
        $this->assertStringNotContainsString('characters long', collect(InvitationDesignNotices::for($short))->pluck('message')->implode(' '));
    }

    // ── Phase 3: no description ──

    public function test_a_missing_description_never_borrows_wedding_words_for_another_kind_of_event(): void
    {
        $birthday = $this->event(['event_type' => 'birthday', 'description' => null]);
        $wedding = $this->event(['event_type' => 'wedding', 'description' => '']);
        $written = $this->event(['event_type' => 'birthday', 'description' => 'Cake at four.']);

        $this->assertSame('Please join us to celebrate. We would love to have you with us.', InvitationDescriptionFallback::for($birthday, 'wedding words'));
        $this->assertSame('wedding words', InvitationDescriptionFallback::for($wedding, 'wedding words'));
        $this->assertSame('Cake at four.', InvitationDescriptionFallback::for($written, 'wedding words'));
        $this->assertSame(InvitationDescriptionFallback::GENERIC, InvitationDescriptionFallback::for($this->event(['event_type' => 'something_else', 'description' => null])));
    }

    public function test_a_birthday_without_a_description_shows_no_wedding_words_on_any_layout(): void
    {
        $checked = 0;

        foreach (InvitationTemplate::query()->get() as $template) {
            $event = $this->event(['event_type' => 'birthday', 'description' => null, 'invitation_template_id' => $template->id]);
            $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

            $this->assertStringNotContainsString('celebrate the union of two souls', $html, "{$template->slug} must not print wedding words for a birthday");
            $this->assertStringNotContainsString('We are getting married, and we would love', $html, $template->slug);
            $checked++;
        }

        $this->assertGreaterThanOrEqual(10, $checked);
    }

    public function test_the_host_is_told_when_there_is_no_description(): void
    {
        $event = $this->event(['description' => null]);

        $this->assertStringContainsString('no description yet', collect(InvitationDesignNotices::for($event))->pluck('message')->implode(' '));

        $event->update(['description' => 'Something.']);
        $this->assertStringNotContainsString('no description yet', collect(InvitationDesignNotices::for($event->fresh()))->pluck('message')->implode(' '));
    }

    // ── Phase 4: no location ──

    public function test_every_layout_says_the_venue_is_to_be_announced_when_there_is_no_place(): void
    {
        foreach (InvitationTemplate::query()->get() as $template) {
            $event = $this->event($this->noPlace() + ['invitation_template_id' => $template->id]);

            $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/to be announced/i', $html, "{$template->slug} must not leave the venue blank");
        }
    }

    public function test_a_venue_without_a_pin_offers_a_maps_search(): void
    {
        $template = InvitationTemplate::query()->where('layout_variant', 'modern_minimal')->first();
        $event = $this->event(['venue' => 'Hotel Taj', 'location_name' => null, 'latitude' => null, 'longitude' => null, 'invitation_template_id' => $template->id]);

        $this->assertStringContainsString('google.com/maps/search/?api=1&amp;query=Hotel+Taj', $this->get(route('events.public', $event->slug))->assertOk()->getContent());
        $this->assertNull(EventPlace::searchUrl($this->event(['venue' => 'X', 'latitude' => -15.4, 'longitude' => 28.3])));
        $this->assertTrue(EventPlace::isUnknown($this->event($this->noPlace())));
        $this->assertFalse(EventPlace::isUnknown($event));
    }

    public function test_the_open_rsvp_page_does_not_leave_the_venue_blank(): void
    {
        $event = $this->event($this->noPlace());

        $this->get(route('rsvp.open.show', $event->slug))->assertOk()->assertSee('Venue to be announced');
    }

    public function test_the_calendar_file_omits_an_empty_location_and_folds_long_lines(): void
    {
        $none = $this->event($this->noPlace() + ['event_time' => '15:00:00']);
        $this->assertStringNotContainsString('LOCATION', EventIcsDocument::build($none));

        $long = $this->event([
            'name' => str_repeat('é', 120), 'venue' => str_repeat('Venue ', 40), 'description' => str_repeat('word ', 200), 'event_time' => '15:00:00',
        ]);
        $ics = EventIcsDocument::build($long);

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'no content line may exceed 75 octets');
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'a fold must not split a character');
        }
        $this->assertStringContainsString(str_repeat('é', 120), str_replace("\r\n ", '', $ics));
    }

    // ── Phase 5: RSVP not available ──

    public function test_a_closed_rsvp_has_no_jump_button_one_anchor_and_the_host_number_on_every_layout(): void
    {
        foreach (InvitationTemplate::query()->get() as $template) {
            $event = $this->event([
                'invitation_template_id' => $template->id,
                'rsvp_deadline' => now(config('events.timezone'))->subDays(2)->format('Y-m-d H:i:s'),
            ]);

            $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

            $this->assertDoesNotMatchRegularExpression('/href="#rsvp(-form)?"/', $html, "{$template->slug}: nothing to jump to");
            $this->assertSame(1, substr_count($html, 'id="rsvp"'), "{$template->slug}: exactly one #rsvp anchor");
            $this->assertStringContainsString('0977 123 456', $html, "{$template->slug}: closed RSVP still shows who to call");
        }
    }

    public function test_an_open_rsvp_keeps_its_jump_button_and_one_anchor(): void
    {
        foreach (['event_invite', 'wedding_invitation_noir', 'modern_minimal'] as $variant) {
            $template = InvitationTemplate::query()->where('layout_variant', $variant)->first();
            $event = $this->event(['invitation_template_id' => $template->id]);

            $html = $this->get(route('events.public', $event->slug))->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/href="#rsvp(-form)?"/', $html, "{$variant} still offers the jump while the form is there");
            $this->assertSame(1, substr_count($html, 'id="rsvp"'), "{$variant}: exactly one #rsvp anchor");
        }
    }

    public function test_the_form_is_shown_only_in_the_cases_the_rsvp_section_renders_one(): void
    {
        $this->assertFalse(InvitationRsvpState::formShown(false, true, false, true, true));
        $this->assertTrue(InvitationRsvpState::formShown(true, true, false, false, false), 'a guest on their personal link');
        $this->assertTrue(InvitationRsvpState::formShown(true, false, true, false, false), 'a host preview');
        $this->assertTrue(InvitationRsvpState::formShown(true, false, false, true, true), 'the public form');
        $this->assertFalse(InvitationRsvpState::formShown(true, false, false, false, true), 'a banner asking for the personal link');
        $this->assertFalse(InvitationRsvpState::formShown(true, false, false, true, false), 'no slug, no public form');
    }

    public function test_the_host_is_warned_when_the_layout_has_no_rsvp_section(): void
    {
        $template = InvitationTemplate::query()->where('layout_variant', 'standard')->first();
        $event = $this->event(['invitation_template_id' => $template->id]);
        $this->assertStringNotContainsString('no RSVP section', collect(InvitationTemplateNotices::for($event))->pluck('message')->implode(' '));

        $template->forceFill(['default_sections' => array_values(array_filter(
            $template->default_sections ?? [],
            fn ($row) => ! is_array($row) || ($row['type'] ?? null) !== 'rsvp'
        ))])->save();

        $this->assertStringContainsString('no RSVP section', collect(InvitationTemplateNotices::for($event->fresh()))->pluck('message')->implode(' '));
    }

    // ── Phase 6: other places ──

    public function test_email_subjects_and_whatsapp_variables_are_kept_short(): void
    {
        $name = str_repeat('Longname ', 30);

        $this->assertLessThanOrEqual(ShortText::SUBJECT, mb_strlen(ShortText::subject($name)));
        $this->assertLessThanOrEqual(ShortText::WHATSAPP, mb_strlen(ShortText::whatsapp($name)));
        $this->assertSame('Short', ShortText::subject('Short'));
        $this->assertSame('a b c', ShortText::whatsapp("a\n  b\t c"), 'one line, whitespace collapsed');

        $event = $this->event(['name' => $name]);
        $guest = Guest::factory()->for($event)->create();
        $subject = (new EventUpdatedNotification($event, $guest, 'The venue changed.'))->toMail($guest)->subject;

        $this->assertLessThanOrEqual(mb_strlen('Update: ') + ShortText::SUBJECT, mb_strlen($subject));
        $this->assertStringEndsWith('…', $subject);
    }
}
