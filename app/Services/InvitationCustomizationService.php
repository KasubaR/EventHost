<?php

namespace App\Services;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Support\InvitationFonts;
use App\Support\InvitationLayoutVariant;
use App\Support\InvitationSections;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvitationCustomizationService
{
    /** Invitation JSON blob written to {@see Event::$invitation_customization}. */
    public const CURRENT_SCHEMA_VERSION = 2;

    /**
     * Stable fingerprint of a template's section set (sorted types, MD5).
     * Embed in forms so a stale submit after a template change can be detected.
     */
    public function templateFingerprint(InvitationTemplate $template): string
    {
        $types = collect($template->default_sections ?? [])
            ->pluck('type')
            ->sort()
            ->values()
            ->implode(',');

        $variant = InvitationLayoutVariant::normalize($template->layout_variant ?? null);

        return md5($variant.'|'.$types);
    }

    /**
     * Resolve template for event (fallback to first active).
     *
     * Uses one query: prefer the event's template when it is active; otherwise the first active row by sort order.
     */
    public function resolvedTemplate(Event $event): InvitationTemplate
    {
        $preferredId = $event->invitation_template_id;

        $query = InvitationTemplate::query()
            ->where('is_active', true);

        if ($preferredId !== null) {
            $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$preferredId]);
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
     *         contact_phone_primary: string,
     *         contact_phone_secondary: string,
     *     },
     * }
     */
    public function merge(Event $event): array
    {
        $template = $this->resolvedTemplate($event);

        $defaults = $this->defaultCustomizationShape($template);
        $stored = $this->normalizeStoredCustomizationInput($event, $event->invitation_customization);
        $stored = $this->migrateStoredForMerge($stored);

        // Extract only known keys from stored data to prevent unexpected fields leaking through.
        $storedTheme = is_array($stored['theme'] ?? null) ? $stored['theme'] : [];
        $theme = [
            'primary' => $storedTheme['primary'] ?? $defaults['theme']['primary'],
            'accent' => $storedTheme['accent'] ?? $defaults['theme']['accent'],
            'background' => $storedTheme['background'] ?? $defaults['theme']['background'],
            'font_heading_key' => $storedTheme['font_heading_key'] ?? $defaults['theme']['font_heading_key'],
            'font_body_key' => $storedTheme['font_body_key'] ?? $defaults['theme']['font_body_key'],
        ];

        $storedEffects = is_array($stored['effects'] ?? null) ? $stored['effects'] : [];
        $effects = [
            'animation_subtle' => $storedEffects['animation_subtle'] ?? $defaults['effects']['animation_subtle'],
            'countdown_enabled' => $storedEffects['countdown_enabled'] ?? $defaults['effects']['countdown_enabled'],
            'video_background' => $storedEffects['video_background'] ?? $defaults['effects']['video_background'],
            'audio_track' => $storedEffects['audio_track'] ?? $defaults['effects']['audio_track'],
        ];

        $sections = $this->mergeSections(
            $template->default_sections ?? [],
            $stored['sections'] ?? null,
            $template
        );

        $storedMedia = is_array($stored['media'] ?? null) ? $stored['media'] : [];
        $heroRaw = $storedMedia['hero_portrait'] ?? null;
        $heroPortrait = is_string($heroRaw) && $heroRaw !== '' ? $heroRaw : null;
        $coupleRaw = $storedMedia['couple_photos'] ?? [];
        $couplePhotos = [];
        if (is_array($coupleRaw)) {
            $couplePhotos = array_values(array_filter(array_map('strval', $coupleRaw)));
        }

        $media = [
            'gallery' => array_values(array_filter(
                array_map('strval', $storedMedia['gallery'] ?? [])
            )),
            'hero_portrait' => $heroPortrait,
            'couple_photos' => $couplePhotos,
        ];

        $storedContent = is_array($stored['content'] ?? null) ? $stored['content'] : [];
        $content = [
            'story' => isset($storedContent['story']) ? (string) $storedContent['story'] : '',
            'schedule' => self::normalizeScheduleItems($storedContent['schedule'] ?? []),
            'speaker_cards' => self::normalizeSpeakerCards($storedContent['speaker_cards'] ?? []),
            'venue_note' => self::normalizeOptionalLine($storedContent['venue_note'] ?? null, 500),
            'bfa_conference_theme' => self::normalizeOptionalLine($storedContent['bfa_conference_theme'] ?? null, 160),
            'bfa_dress_code' => self::normalizeOptionalLine($storedContent['bfa_dress_code'] ?? null, 160),
            'bfa_presenter_line' => self::normalizeOptionalLine($storedContent['bfa_presenter_line'] ?? null, 200),
            'bfa_presents_line' => self::normalizeOptionalLine($storedContent['bfa_presents_line'] ?? null, 120),
            'bfa_tagline_bar' => self::normalizeOptionalLine($storedContent['bfa_tagline_bar'] ?? null, 200),
            'contact_phone_primary' => self::normalizeOptionalLine($storedContent['contact_phone_primary'] ?? null, 40),
            'contact_phone_secondary' => self::normalizeOptionalLine($storedContent['contact_phone_secondary'] ?? null, 40),
        ];

        $headingFont = InvitationFonts::normalizeKey((string) ($theme['font_heading_key'] ?? 'system_ui'));
        $bodyFont = InvitationFonts::normalizeKey((string) ($theme['font_body_key'] ?? 'system_ui'));
        $layoutVariant = InvitationLayoutVariant::normalize($template->layout_variant ?? null);

        $googleFonts = InvitationFonts::googleFamiliesNeeded($headingFont, $bodyFont);
        if ($layoutVariant === InvitationLayoutVariant::BEAUTY_FOR_ASHES) {
            $cinzelSpec = InvitationFonts::MAP['cinzel']['google_family'] ?? null;
            if (is_string($cinzelSpec) && $cinzelSpec !== '' && ! in_array($cinzelSpec, $googleFonts, true)) {
                $googleFonts[] = $cinzelSpec;
            }
        }

        return [
            'skin' => $template->skin,
            'layout_variant' => $layoutVariant,
            'theme' => [
                'primary' => (string) ($theme['primary'] ?? '#1a2a4a'),
                'accent' => (string) ($theme['accent'] ?? '#1e47bb'),
                'background' => (string) ($theme['background'] ?? '#fafafa'),
                'font_heading_stack' => InvitationFonts::stack($headingFont),
                'font_body_stack' => InvitationFonts::stack($bodyFont),
                'font_heading_key' => $headingFont,
                'font_body_key' => $bodyFont,
                'google_font_families' => $googleFonts,
            ],
            'sections' => $sections,
            'content' => $content,
            'media' => $media,
            'effects' => [
                'animation_subtle' => (bool) ($effects['animation_subtle'] ?? false),
                'countdown_enabled' => (bool) ($effects['countdown_enabled'] ?? true),
                'video_background' => isset($effects['video_background']) && $effects['video_background'] !== ''
                    ? (string) $effects['video_background']
                    : null,
                'audio_track' => isset($effects['audio_track']) && $effects['audio_track'] !== ''
                    ? (string) $effects['audio_track']
                    : null,
            ],
            'schema_version' => self::CURRENT_SCHEMA_VERSION,
        ];
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
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $timeRaw = trim((string) ($row['time'] ?? ''));
            $detailRaw = trim((string) ($row['detail'] ?? ''));
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
     * @return array<string, mixed>
     */
    public function defaultCustomizationShape(InvitationTemplate $template): array
    {
        $dt = $template->default_theme ?? [];

        return [
            'schema_version' => self::CURRENT_SCHEMA_VERSION,
            'theme' => [
                'primary' => (string) ($dt['primary'] ?? '#1a2a4a'),
                'accent' => (string) ($dt['accent'] ?? '#1e47bb'),
                'background' => (string) ($dt['background'] ?? '#fafafa'),
                'font_heading_key' => InvitationFonts::normalizeKey((string) ($dt['font_heading_key'] ?? 'system_ui')),
                'font_body_key' => InvitationFonts::normalizeKey((string) ($dt['font_body_key'] ?? 'system_ui')),
            ],
            'effects' => [
                'animation_subtle' => (bool) ($dt['animation_subtle'] ?? false),
                'countdown_enabled' => (bool) ($dt['countdown_enabled'] ?? true),
                'video_background' => null,
                'audio_track' => null,
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

        foreach ($templateFallback as $row) {
            if (isset($seen[$row['type']])) {
                continue;
            }
            $seen[$row['type']] = true;
            $ordered[] = $row;
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
            $role = Str::limit(trim((string) ($row['role'] ?? '')), 80, '');
            $name = Str::limit(trim((string) ($row['name'] ?? '')), 120, '');
            if ($role === '' && $name === '') {
                continue;
            }
            $out[] = ['role' => $role, 'name' => $name];
        }

        return $out;
    }

    public static function normalizeOptionalLine(mixed $raw, int $max): string
    {
        $s = trim((string) $raw);

        return $s === '' ? '' : Str::limit($s, $max, '');
    }
}
