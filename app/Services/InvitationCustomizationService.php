<?php

namespace App\Services;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Support\InvitationFonts;
use App\Support\InvitationLayoutVariant;
use App\Support\InvitationMediaHealth;
use App\Support\InvitationPalettes;
use App\Support\InvitationSections;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvitationCustomizationService
{
    /** Invitation JSON blob written to {@see Event::$invitation_customization}. */
    public const CURRENT_SCHEMA_VERSION = 2;

    /** RSVP form optional fields (in display order). */
    public const RSVP_FORM_FIELDS = ['message'];

    /** Default labels for RSVP form fields (standard / non-template-specific). */
    public const RSVP_FORM_DEFAULT_LABELS = [
        'message' => 'Message to host',
    ];

    /**
     * Template-aware default RSVP form config (all fields visible, labels per variant).
     *
     * @return array<string, array{visible: bool, label: string}>
     */
    public static function defaultRsvpForm(string $variant): array
    {
        $labels = match ($variant) {
            InvitationLayoutVariant::BEAUTY_FOR_ASHES => [
                'message' => 'What are your expectations',
            ],
            default => self::RSVP_FORM_DEFAULT_LABELS,
        };

        $out = [];
        foreach (self::RSVP_FORM_FIELDS as $field) {
            $out[$field] = ['visible' => false, 'label' => $labels[$field]];
        }

        return $out;
    }

    /**
     * Resolve the RSVP form config for an event — uses stored values where present,
     * falls back to template-variant defaults for any missing field.
     *
     * @return array<string, array{visible: bool, label: string}>
     */
    public function resolveRsvpFormConfig(Event $event): array
    {
        $stored = is_array($event->invitation_customization) ? $event->invitation_customization : [];
        $storedRsvpForm = is_array($stored['rsvp_form'] ?? null) ? $stored['rsvp_form'] : [];

        $variant = InvitationLayoutVariant::normalize(
            $event->relationLoaded('invitationTemplate')
                ? ($event->invitationTemplate?->layout_variant ?? null)
                : null
        );
        $defaults = self::defaultRsvpForm($variant);

        $out = [];
        foreach (self::RSVP_FORM_FIELDS as $field) {
            $storedField = is_array($storedRsvpForm[$field] ?? null) ? $storedRsvpForm[$field] : [];
            $default = $defaults[$field];
            $out[$field] = [
                'visible' => isset($storedField['visible']) ? (bool) $storedField['visible'] : $default['visible'],
                'label' => (is_string($storedField['label'] ?? null) && trim((string) $storedField['label']) !== '')
                    ? trim((string) $storedField['label'])
                    : $default['label'],
            ];
        }

        return $out;
    }

    /**
     * Stable fingerprint of a template's section set (sorted types, MD5).
     * Embed in forms so a stale submit after a template change can be detected.
     */
    public function templateFingerprint(InvitationTemplate $template): string
    {
        $types = collect($template->default_sections ?? [])
            ->filter(fn ($row) => is_array($row) && is_string($row['type'] ?? null))
            ->pluck('type')
            ->sort()
            ->values()
            ->implode(',');

        $variant = InvitationLayoutVariant::normalize($template->layout_variant ?? null);

        // The id is part of it: two layouts can share a variant and section set, and
        // a form opened on one must still be refused once the event moved to the other.
        return md5($template->getKey().'|'.$variant.'|'.$types);
    }

    /**
     * The event's stored customization as an array, shape not guaranteed.
     *
     * When the current value cannot be read (corrupt JSON reads back as null through the
     * array cast) but the one-level previous copy can, the previous copy is used and
     * $restored is set, so the host is told rather than silently shown the defaults.
     *
     * @return array<string, mixed>
     */
    public function storedCustomization(Event $event, ?bool &$restored = null): array
    {
        $restored = false;

        $current = $this->normalizeStoredCustomizationInput($event, $event->invitation_customization);
        if ($current !== []) {
            return $current;
        }

        $previous = $this->normalizeStoredCustomizationInput($event, $event->invitation_customization_previous);
        if ($previous === []) {
            return [];
        }

        $restored = true;
        Log::warning('invitation_customization.restored_from_previous', [
            'event_id' => $event->exists ? $event->getKey() : null,
        ]);

        return $previous;
    }

    /**
     * Stored media lists, tolerant of any shape: non-strings are dropped, a list
     * stored as a scalar reads as empty.
     *
     * @param  array<string, mixed>  $stored  from storedCustomization()
     * @return array{gallery: list<string>, hero_portrait: ?string, couple_photos: list<string>, unoptimised: list<string>}
     */
    public static function storedMedia(array $stored): array
    {
        $media = is_array($stored['media'] ?? null) ? $stored['media'] : [];

        $strings = static fn (mixed $list, bool $keepBlank = false): array => is_array($list)
            ? array_values(array_filter($list, static fn ($v) => is_string($v) && ($keepBlank || $v !== '')))
            : [];

        $hero = $media['hero_portrait'] ?? null;

        return [
            'gallery' => $strings($media['gallery'] ?? null),
            'hero_portrait' => is_string($hero) && $hero !== '' ? $hero : null,
            'couple_photos' => $strings($media['couple_photos'] ?? null, true),
            'unoptimised' => $strings($media['unoptimised'] ?? null),
        ];
    }

    /**
     * Colours to write when a save carries no palette (no Pro+, or a layout without the
     * picker). Stored colours are kept only when all three are valid #rrggbb; anything
     * else is replaced by the template's own trio, never copied forward.
     *
     * @param  array<string, mixed>  $storedTheme
     * @return array{palette_key: ?string, primary: string, accent: string, background: string}
     */
    public static function themeColoursToKeep(array $storedTheme, InvitationTemplate $template): array
    {
        if (InvitationPalettes::isUsableTrio($storedTheme)) {
            $key = $storedTheme['palette_key'] ?? null;

            return [
                'palette_key' => is_string($key) && in_array($key, InvitationPalettes::storableKeys(), true) ? $key : null,
                'primary' => trim((string) $storedTheme['primary']),
                'accent' => trim((string) $storedTheme['accent']),
                'background' => trim((string) $storedTheme['background']),
            ];
        }

        $default = InvitationPalettes::templateDefault($template->default_theme);

        return [
            'palette_key' => InvitationPalettes::TEMPLATE_DEFAULT_KEY,
            'primary' => $default['primary'],
            'accent' => $default['accent'],
            'background' => $default['background'],
        ];
    }

    /**
     * Whether the layout must show its RSVP section: every invitation (non-ticketed)
     * event whose layout has one and does not block it. Hiding it would leave guests
     * on their personal link with no way to answer.
     */
    public static function rsvpSectionRequired(Event $event, InvitationTemplate $template): bool
    {
        if ($event->isTicketed()) {
            return false;
        }

        $variant = InvitationLayoutVariant::normalize($template->layout_variant ?? null);
        if (in_array(InvitationSections::RSVP, InvitationLayoutVariant::blockedSections($variant), true)) {
            return false;
        }

        foreach ($template->default_sections ?? [] as $row) {
            if (is_array($row) && ($row['type'] ?? null) === InvitationSections::RSVP) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve template for event (fallback to first active).
     *
     * Uses one query. The event's own template wins even once it is retired (is_active = false):
     * a live invitation keeps the layout its host chose, and only new selections are refused.
     * The first active row by sort order is used only when the event has no template at all;
     * guest-facing pages refuse that case before reaching here (PublicInvitationResolver).
     */
    public function resolvedTemplate(Event $event): InvitationTemplate
    {
        $preferredId = $event->invitation_template_id;

        $query = InvitationTemplate::query();

        if ($preferredId !== null) {
            $query->where(fn ($q) => $q->whereKey($preferredId)->orWhere('is_active', true))
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$preferredId]);
        } else {
            $query->where('is_active', true);
        }

        $tpl = $query->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($tpl === null) {
            throw new \RuntimeException('No invitation templates are configured.');
        }

        $event->setRelation('invitationTemplate', $tpl);

        return $tpl;
    }

    /**
     * @return array{
     *     skin: string,
     *     layout_variant: string,
     *     theme: array<string, mixed>,
     *     sections: list<array{type: string, visible: bool}>,
     *     media: array{gallery: list<string>, hero_portrait: ?string, couple_photos: list<string>},
     *     effects: array{
     *         animation_subtle: bool,
     *         countdown_enabled: bool,
     *         video_background: ?string,
     *         audio_track: ?string
     *     },
     *     content: array{
     *         story: string,
     *         schedule: list<array{time: ?string, title: string, detail: ?string}>,
     *         speaker_cards: list<array{role: string, name: string}>,
     *         venue_note: string,
     *         bfa_conference_theme: string,
     *         bfa_dress_code: string,
     *         bfa_presenter_line: string,
     *         bfa_presents_line: string,
     *         bfa_tagline_bar: string,
     *         bfa_tagline_quote: string,
     *         bfa_host_slot: int,
     *         contact_phone_primary: string,
     *         contact_phone_secondary: string,
     *         ei_color_theme: string,
     *         ei_guest_speaker: string,
     *         ei_mc: string,
     *         wi_hero_eyebrow: string,
     *         wi_couple_caption: string,
     *         wi_footer_quote: string,
     *         wi2_hero_tag: string,
     *         wi2_invite_formal: string,
     *         wi2_invite_body: string,
     *         wi2_photo_quote: string,
     *         wi2_photo_quote_cite: string,
     *         wi2_footer_monogram: string,
     *         wi2_footer_legal: string,
     *     },
     *     rsvp_form: array<string, array{visible: bool, label: string}>,
     * }
     */
    public function merge(Event $event, bool $hideMissingMedia = false): array
    {
        $template = $this->resolvedTemplate($event);

        $defaults = $this->defaultCustomizationShape($template);
        $stored = $this->storedCustomization($event, $restoredFromPrevious);
        $stored = $this->migrateStoredForMerge($stored);

        // Extract only known keys from stored data to prevent unexpected fields leaking through.
        // Every read below tolerates any JSON shape: a hand edit or a partial write must
        // never take the invitation, the edit page or a save down with it.
        $storedTheme = is_array($stored['theme'] ?? null) ? $stored['theme'] : [];
        $candidateTrio = [
            'primary' => $storedTheme['primary'] ?? $defaults['theme']['primary'],
            'accent' => $storedTheme['accent'] ?? $defaults['theme']['accent'],
            'background' => $storedTheme['background'] ?? $defaults['theme']['background'],
        ];
        // The colours land in an inline style on every page. One bad value replaces all
        // three with the template's own: mixing kept and default colours could be unreadable.
        $trio = InvitationPalettes::isUsableTrio($candidateTrio)
            ? array_map(static fn ($v) => trim((string) $v), $candidateTrio)
            : [
                'primary' => $defaults['theme']['primary'],
                'accent' => $defaults['theme']['accent'],
                'background' => $defaults['theme']['background'],
            ];

        $storedEffects = is_array($stored['effects'] ?? null) ? $stored['effects'] : [];
        $effects = [
            'animation_subtle' => $storedEffects['animation_subtle'] ?? $defaults['effects']['animation_subtle'],
            'countdown_enabled' => $storedEffects['countdown_enabled'] ?? $defaults['effects']['countdown_enabled'],
            'video_background' => self::stringOrNull($storedEffects['video_background'] ?? null),
            'audio_track' => self::stringOrNull($storedEffects['audio_track'] ?? null),
            'audio_title' => self::stringOrNull($storedEffects['audio_title'] ?? null),
            'audio_artist' => self::stringOrNull($storedEffects['audio_artist'] ?? null),
        ];

        $sections = $this->mergeSections(
            $template->default_sections ?? [],
            is_array($stored['sections'] ?? null) ? $stored['sections'] : null,
            $template
        );

        if (self::rsvpSectionRequired($event, $template)) {
            foreach ($sections as $i => $row) {
                if ($row['type'] === InvitationSections::RSVP) {
                    $sections[$i]['visible'] = true;
                }
            }
        }

        $layoutVariant = InvitationLayoutVariant::normalize($template->layout_variant ?? null);

        $storedMedia = self::storedMedia($stored);
        $coupleRaw = $storedMedia['couple_photos'];

        // For BFA: keep a fixed 4-slot positional array so each photo stays bound to its speaker slot.
        // For other templates: compact (non-empty paths only).
        if ($layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES) {
            $couplePhotos = [];
            for ($i = 0; $i < 4; $i++) {
                $couplePhotos[] = $coupleRaw[$i] ?? '';
            }
        } else {
            $couplePhotos = array_values(array_filter($coupleRaw, static fn (string $p) => $p !== ''));
        }

        $media = [
            'gallery' => $storedMedia['gallery'],
            'hero_portrait' => $storedMedia['hero_portrait'],
            'couple_photos' => $couplePhotos,
            'unoptimised' => $storedMedia['unoptimised'],
        ];

        $storedContent = is_array($stored['content'] ?? null) ? $stored['content'] : [];
        $content = [
            'story' => self::text($storedContent['story'] ?? null),
            'schedule' => self::normalizeScheduleItems(is_array($storedContent['schedule'] ?? null) ? $storedContent['schedule'] : []),
            'speaker_cards' => self::normalizeSpeakerCards($storedContent['speaker_cards'] ?? []),
            'venue_note' => self::normalizeOptionalLine($storedContent['venue_note'] ?? null, 500),
            'bfa_conference_theme' => self::normalizeOptionalLine($storedContent['bfa_conference_theme'] ?? null, 160),
            'bfa_dress_code' => self::normalizeOptionalLine($storedContent['bfa_dress_code'] ?? null, 160),
            'bfa_presenter_line' => self::normalizeOptionalLine($storedContent['bfa_presenter_line'] ?? null, 200),
            'bfa_presents_line' => self::normalizeOptionalLine($storedContent['bfa_presents_line'] ?? null, 120),
            'bfa_tagline_bar' => self::normalizeOptionalLine($storedContent['bfa_tagline_bar'] ?? null, 200),
            'bfa_tagline_quote' => self::normalizeOptionalLine($storedContent['bfa_tagline_quote'] ?? null, 300),
            'bfa_host_slot' => max(0, min(3, (int) ($storedContent['bfa_host_slot'] ?? 1))),
            'contact_phone_primary' => self::normalizeOptionalLine($storedContent['contact_phone_primary'] ?? null, 40),
            'contact_phone_secondary' => self::normalizeOptionalLine($storedContent['contact_phone_secondary'] ?? null, 40),
            'ei_color_theme' => self::normalizeOptionalLine($storedContent['ei_color_theme'] ?? null, 160),
            'ei_guest_speaker' => self::normalizeOptionalLine($storedContent['ei_guest_speaker'] ?? null, 120),
            'ei_mc' => self::normalizeOptionalLine($storedContent['ei_mc'] ?? null, 120),
            'wi_hero_eyebrow' => self::normalizeOptionalLine($storedContent['wi_hero_eyebrow'] ?? null, 120),
            'wi_couple_caption' => self::normalizeOptionalLine($storedContent['wi_couple_caption'] ?? null, 160),
            'wi_footer_quote' => self::normalizeOptionalLine($storedContent['wi_footer_quote'] ?? null, 300),
            'wi2_hero_tag' => self::normalizeOptionalLine($storedContent['wi2_hero_tag'] ?? null, 120),
            'wi2_invite_formal' => self::normalizeOptionalLine($storedContent['wi2_invite_formal'] ?? null, 160),
            'wi2_invite_body' => self::normalizeOptionalLine($storedContent['wi2_invite_body'] ?? null, 600),
            'wi2_photo_quote' => self::normalizeOptionalLine($storedContent['wi2_photo_quote'] ?? null, 400),
            'wi2_photo_quote_cite' => self::normalizeOptionalLine($storedContent['wi2_photo_quote_cite'] ?? null, 160),
            'wi2_footer_monogram' => self::normalizeOptionalLine($storedContent['wi2_footer_monogram'] ?? null, 24),
            'wi2_footer_legal' => self::normalizeOptionalLine($storedContent['wi2_footer_legal'] ?? null, 120),
        ];

        $storedRsvpForm = is_array($stored['rsvp_form'] ?? null) ? $stored['rsvp_form'] : [];

        // A key dropped from InvitationFonts::MAP falls back to the template's own font
        // (not system_ui), and the original key is reported so the form can say so.
        $fontReplacements = [];
        $fonts = [];
        foreach (['heading' => 'font_heading_key', 'body' => 'font_body_key'] as $role => $field) {
            $storedKey = $storedTheme[$field] ?? null;
            $fonts[$role] = InvitationFonts::resolve($storedKey, $defaults['theme'][$field]);
            if (is_string($storedKey) && trim($storedKey) !== '' && ! InvitationFonts::exists($storedKey)) {
                $fontReplacements[$role] = trim($storedKey);
            }
        }
        $headingFont = $fonts['heading'];
        $bodyFont = $fonts['body'];

        $googleFonts = InvitationFonts::googleFamiliesNeeded($headingFont, $bodyFont);
        if ($layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES) {
            $cinzelSpec = InvitationFonts::MAP['cinzel']['google_family'] ?? null;
            if (is_string($cinzelSpec) && $cinzelSpec !== '' && ! in_array($cinzelSpec, $googleFonts, true)) {
                $googleFonts[] = $cinzelSpec;
            }
        }
        if ($layoutVariant === InvitationLayoutVariant::EVENT_INVITE) {
            foreach (['great_vibes', 'cormorant_garamond', 'montserrat'] as $fontKey) {
                $spec = InvitationFonts::MAP[$fontKey]['google_family'] ?? null;
                if (is_string($spec) && $spec !== '' && ! in_array($spec, $googleFonts, true)) {
                    $googleFonts[] = $spec;
                }
            }
        }
        if ($layoutVariant === InvitationLayoutVariant::WEDDING_INVITATION) {
            foreach (['playfair', 'cormorant_garamond', 'jost'] as $fontKey) {
                $spec = InvitationFonts::MAP[$fontKey]['google_family'] ?? null;
                if (is_string($spec) && $spec !== '' && ! in_array($spec, $googleFonts, true)) {
                    $googleFonts[] = $spec;
                }
            }
        }
        if ($layoutVariant === InvitationLayoutVariant::WEDDING_INVITATION_NOIR) {
            foreach (['bodoni_moda', 'eb_garamond', 'cinzel'] as $fontKey) {
                $spec = InvitationFonts::MAP[$fontKey]['google_family'] ?? null;
                if (is_string($spec) && $spec !== '' && ! in_array($spec, $googleFonts, true)) {
                    $googleFonts[] = $spec;
                }
            }
        }
        if ($layoutVariant === InvitationLayoutVariant::MODERN_MINIMAL) {
            foreach (['dm_sans', 'inter'] as $fontKey) {
                $spec = InvitationFonts::MAP[$fontKey]['google_family'] ?? null;
                if (is_string($spec) && $spec !== '' && ! in_array($spec, $googleFonts, true)) {
                    $googleFonts[] = $spec;
                }
            }
        }
        if ($layoutVariant === InvitationLayoutVariant::WEDDING_MIDNIGHT_GOLD) {
            foreach (['dancing_script', 'poppins'] as $fontKey) {
                $spec = InvitationFonts::MAP[$fontKey]['google_family'] ?? null;
                if (is_string($spec) && $spec !== '' && ! in_array($spec, $googleFonts, true)) {
                    $googleFonts[] = $spec;
                }
            }
        }
        if ($layoutVariant === InvitationLayoutVariant::WEDDING_DUSTY_BLUE) {
            foreach (['cormorant_garamond', 'mrs_saint_delafield'] as $fontKey) {
                $spec = InvitationFonts::MAP[$fontKey]['google_family'] ?? null;
                if (is_string($spec) && $spec !== '' && ! in_array($spec, $googleFonts, true)) {
                    $googleFonts[] = $spec;
                }
            }
        }

        $rsvpFormDefaults = self::defaultRsvpForm($layoutVariant);
        $rsvpForm = [];
        foreach (self::RSVP_FORM_FIELDS as $field) {
            $storedField = is_array($storedRsvpForm[$field] ?? null) ? $storedRsvpForm[$field] : [];
            $default = $rsvpFormDefaults[$field];
            $rsvpForm[$field] = [
                'visible' => isset($storedField['visible']) ? (bool) $storedField['visible'] : $default['visible'],
                'label' => (is_string($storedField['label'] ?? null) && trim((string) $storedField['label']) !== '')
                    ? trim((string) $storedField['label'])
                    : $default['label'],
            ];
        }

        $merged = [
            'skin' => $template->skin,
            'layout_variant' => $layoutVariant,
            'theme' => [
                'primary' => $trio['primary'],
                'accent' => $trio['accent'],
                'background' => $trio['background'],
                'font_heading_stack' => InvitationFonts::stack($headingFont),
                'font_body_stack' => InvitationFonts::stack($bodyFont),
                'font_heading_key' => $headingFont,
                'font_body_key' => $bodyFont,
                'font_replacements' => $fontReplacements,
                'google_font_families' => $googleFonts,
            ],
            'sections' => $sections,
            'content' => $content,
            'media' => $media,
            'effects' => [
                'animation_subtle' => (bool) ($effects['animation_subtle'] ?? false),
                'countdown_enabled' => (bool) ($effects['countdown_enabled'] ?? true),
                'video_background' => $effects['video_background'],
                'audio_track' => $effects['audio_track'],
                'audio_title' => $effects['audio_title'],
                'audio_artist' => $effects['audio_artist'],
            ],
            'rsvp_form' => $rsvpForm,
            'schema_version' => self::CURRENT_SCHEMA_VERSION,
            'restored_from_previous' => (bool) $restoredFromPrevious,
        ];

        // Guest-facing callers pass true: a file that is gone is skipped instead of drawn as a broken image.
        // Host-facing ones keep the reference so the editor still shows it and the host can replace it.
        return $hideMissingMedia ? InvitationMediaHealth::hide($merged) : $merged;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A stored scalar as a string; arrays and objects read as empty. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Normalize legacy stored payloads before merge. Extend with v1→v2 (etc.) steps when schema evolves.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    protected function migrateStoredForMerge(array $stored): array
    {
        $version = (int) ($stored['schema_version'] ?? 1);
        if ($version < 1) {
            $stored['schema_version'] = 1;
            $version = 1;
        }

        // Example future migration:
        // while ($version < self::CURRENT_SCHEMA_VERSION) {
        //     $stored = match ($version) {
        //         1 => $this->migrateCustomizationV1ToV2($stored),
        //         default => $stored,
        //     };
        //     $version = (int) ($stored['schema_version'] ?? $version + 1);
        // }

        return $stored;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array{time: ?string, title: string, detail: ?string}>
     */
    public static function normalizeScheduleItems(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim(self::text($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $timeRaw = trim(self::text($row['time'] ?? ''));
            $detailRaw = trim(self::text($row['detail'] ?? ''));
            $out[] = [
                'time' => $timeRaw !== '' ? $timeRaw : null,
                'title' => $title,
                'detail' => $detailRaw !== '' ? $detailRaw : null,
            ];
        }

        return $out;
    }

    /**
     * Normalize raw invitation_customization attribute before merge (handles legacy JSON strings).
     *
     * @return array<string, mixed>
     */
    protected function normalizeStoredCustomizationInput(Event $event, mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                Log::notice('invitation_customization.decoded_from_string', [
                    'event_id' => $event->exists ? $event->getKey() : null,
                ]);

                return $decoded;
            }

            Log::warning('invitation_customization.string_not_valid_json', [
                'event_id' => $event->exists ? $event->getKey() : null,
            ]);
        }

        return [];
    }

    /**
     * Produce the canonical sections array for persistence so it matches merge() on read
     * (deduplication, allowed types, and template fallbacks).
     *
     * @param  list<array{type: string, visible: bool}>  $storedRows
     * @return list<array{type: string, visible: bool}>
     */
    public function mergeSectionsForPersistence(InvitationTemplate $template, array $storedRows): array
    {
        return $this->mergeSections(
            $template->default_sections ?? [],
            $storedRows,
            $template
        );
    }

    /**
     * Point the event's saved colours at a newly chosen template's own default.
     * Colours saved for the old template would otherwise carry over — and a light
     * trio on a dark layout (or the reverse) is exactly the unreadable combination
     * the palette catalogue exists to prevent. Fonts, sections, content and media
     * are left alone. Sets the attribute only; the caller saves.
     */
    public function resetThemeColoursForTemplate(Event $event, InvitationTemplate $template): void
    {
        $stored = $this->normalizeStoredCustomizationInput($event, $event->invitation_customization);
        if (! is_array($stored['theme'] ?? null)) {
            return;
        }

        $default = InvitationPalettes::templateDefault($template->default_theme);

        $stored['theme'] = array_merge($stored['theme'], [
            'palette_key' => InvitationPalettes::TEMPLATE_DEFAULT_KEY,
            'primary' => $default['primary'],
            'accent' => $default['accent'],
            'background' => $default['background'],
        ]);

        $event->invitation_customization = $stored;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultCustomizationShape(InvitationTemplate $template): array
    {
        $dt = is_array($template->default_theme) ? $template->default_theme : [];

        $hex = static fn (string $key): string => InvitationPalettes::isHex($dt[$key] ?? null)
            ? trim((string) $dt[$key])
            : InvitationPalettes::FALLBACK[$key];

        $primary = $hex('primary');
        $accent = $hex('accent');
        $background = $hex('background');

        return [
            'schema_version' => self::CURRENT_SCHEMA_VERSION,
            'theme' => [
                // The template's own colours, whether or not a catalogue palette shares them.
                'palette_key' => InvitationPalettes::TEMPLATE_DEFAULT_KEY,
                'primary' => $primary,
                'accent' => $accent,
                'background' => $background,
                'font_heading_key' => InvitationFonts::resolve($dt['font_heading_key'] ?? null),
                'font_body_key' => InvitationFonts::resolve($dt['font_body_key'] ?? null),
            ],
            'effects' => [
                'animation_subtle' => (bool) ($dt['animation_subtle'] ?? false),
                'countdown_enabled' => (bool) ($dt['countdown_enabled'] ?? true),
                'video_background' => null,
                'audio_track' => null,
                'audio_title' => null,
                'audio_artist' => null,
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $templateDefault
     * @param  array<int, mixed>|null  $stored
     * @return list<array{type: string, visible: bool}>
     */
    protected function mergeSections(array $templateDefault, ?array $stored, InvitationTemplate $template): array
    {
        $allowed = $this->allowedTypesFromTemplate($templateDefault);
        $normalizedTemplate = $this->normalizeSectionRows($templateDefault, $allowed);

        if ($stored === null || $stored === []) {
            return $normalizedTemplate;
        }

        $normalizedStored = $this->normalizeSectionRows($stored, $allowed);

        $ordered = $this->dedupeAndOrder($normalizedStored, $normalizedTemplate);

        $variant = InvitationLayoutVariant::normalize($template->layout_variant ?? null);
        $pinned = InvitationLayoutVariant::pinnedFirst($variant);
        if ($pinned !== null) {
            $idx = array_search($pinned, array_column($ordered, 'type'), true);
            if ($idx !== false && $idx !== 0) {
                $row = array_splice($ordered, $idx, 1);
                array_unshift($ordered, $row[0]);
            }
        }

        return $ordered;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  list<string>  $allowed
     * @return list<array{type: string, visible: bool}>
     */
    protected function normalizeSectionRows(array $rows, array $allowed): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = $row['type'] ?? null;
            if (! is_string($type) || ! in_array($type, $allowed, true)) {
                continue;
            }
            $out[] = [
                'type' => $type,
                'visible' => (bool) ($row['visible'] ?? true),
            ];
        }

        return $out;
    }

    /**
     * Section types allowed when merging stored rows against a template.
     *
     * When `default_sections` is empty or yields no known types (e.g. null JSON / malformed rows),
     * falls back to {@see InvitationSections::all()} so invitations remain renderable with full blocks.
     *
     * @param  array<int, mixed>  $templateDefault
     * @return list<string>
     */
    protected function allowedTypesFromTemplate(array $templateDefault): array
    {
        $fromTemplate = [];
        foreach ($templateDefault as $row) {
            if (is_array($row) && isset($row['type']) && is_string($row['type'])) {
                $fromTemplate[] = $row['type'];
            }
        }

        $allowed = array_values(array_intersect($fromTemplate, InvitationSections::all()));

        return $allowed !== [] ? $allowed : InvitationSections::all();
    }

    /**
     * @param  list<array{type: string, visible: bool}>  $stored
     * @param  list<array{type: string, visible: bool}>  $templateFallback
     * @return list<array{type: string, visible: bool}>
     */
    protected function dedupeAndOrder(array $stored, array $templateFallback): array
    {
        $seen = [];
        $ordered = [];
        foreach ($stored as $row) {
            if (isset($seen[$row['type']])) {
                continue;
            }
            $seen[$row['type']] = true;
            $ordered[] = $row;
        }

        // A type the saved order lacks (e.g. a section a layout gained later) goes right
        // after the nearest earlier template section, not at the end — some layouts close
        // the page inside their last section, so appending would land below the footer.
        $previousType = null;
        foreach ($templateFallback as $row) {
            if (! isset($seen[$row['type']])) {
                $seen[$row['type']] = true;
                $anchor = $previousType === null
                    ? false
                    : array_search($previousType, array_column($ordered, 'type'), true);
                array_splice($ordered, $anchor === false ? 0 : $anchor + 1, 0, [$row]);
            }
            $previousType = $row['type'];
        }

        return $ordered;
    }

    /**
     * @return list<array{role: string, name: string}>
     */
    public static function normalizeSpeakerCards(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, 4) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $role = Str::limit(trim(self::text($row['role'] ?? '')), 80, '');
            $name = Str::limit(trim(self::text($row['name'] ?? '')), 120, '');
            if ($role === '' && $name === '') {
                continue;
            }
            $out[] = ['role' => $role, 'name' => $name];
        }

        return $out;
    }

    public static function normalizeOptionalLine(mixed $raw, int $max): string
    {
        $s = trim(self::text($raw));

        return $s === '' ? '' : Str::limit($s, $max, '');
    }
}
