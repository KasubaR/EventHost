<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Services\InvitationCustomizationService;

/**
 * Everything printed on a guest's invitation pass, gathered in one place so the
 * web card, and later the PDF and PNG renderers (plans/invitation-pass-card.md),
 * can never disagree about what the card says. Pure data — no rendering.
 *
 * Eligibility is NOT decided here: callers have already passed
 * Guest::hasEntryPassFor() before building a card.
 */
final class GuestPassCard
{
    public const STATE_VALID = 'valid';

    public const STATE_CHECKED_IN = 'checked_in';

    public const STATE_CANCELLED = 'cancelled';

    public const STATE_ENDED = 'ended';

    /** Fallbacks match InvitationCustomizationService's own theme defaults. */
    private const DEFAULT_PRIMARY = '#1a2a4a';

    private const DEFAULT_ACCENT = '#1e47bb';

    private const DEFAULT_BACKGROUND = '#fafafa';

    /**
     * @param  array{primary: string, accent: string, background: string}  $theme
     */
    private function __construct(
        public readonly string $eventName,
        public readonly string $eventTypeLabel,
        public readonly ?string $dateLine,
        public readonly ?string $timeLine,
        public readonly ?string $venue,
        public readonly string $guestName,
        public readonly int $admits,
        public readonly ?string $table,
        public readonly string $state,
        public readonly ?string $checkedInLine,
        public readonly ?string $coverUrl,
        public readonly array $theme,
    ) {}

    /**
     * @param  array<string, mixed>|null  $theme  Already-merged invitation theme when the caller has it
     *                                            (the RSVP page does); resolved here otherwise.
     */
    public static function for(Guest $guest, Event $event, ?Rsvp $rsvp = null, ?array $theme = null): self
    {
        $rsvp ??= $guest->rsvp;
        $guest->loadMissing('eventTable');

        $state = match (true) {
            $event->isCancelled() => self::STATE_CANCELLED,
            $guest->isCheckedIn() => self::STATE_CHECKED_IN,
            $event->isLocked() => self::STATE_ENDED,
            default => self::STATE_VALID,
        };

        $venue = collect([$event->venue, $event->location_name])
            ->filter(fn ($part): bool => is_string($part) && trim($part) !== '')
            ->unique()
            ->first();

        return new self(
            eventName: (string) $event->name,
            eventTypeLabel: $event->event_type_label,
            dateLine: $event->event_date?->format('l, F j, Y'),
            timeLine: $event->hasStartTime() ? substr((string) $event->event_time, 0, 5) : null,
            venue: $venue,
            guestName: (string) $guest->name,
            admits: max(1, (int) ($rsvp?->attendee_count ?? 1)),
            table: $guest->tableLabel(),
            state: $state,
            checkedInLine: $guest->isCheckedIn()
                ? 'Checked in '.$guest->checked_in_at->timezone($event->venueTimezone())->format('j M, g:i A')
                : null,
            coverUrl: filled($event->cover_image) ? $event->cover_image_url : null,
            theme: self::resolveTheme($event, $theme),
        );
    }

    /**
     * What the party size means at the door. An invitation RSVP is 1 (just the
     * guest) or 2 (guest plus their one guest), so a bare "Admits: 1" tells the
     * door nothing. Null for a solo guest — renderers omit the row, as they do
     * for an unassigned table.
     */
    public function partyLabel(): ?string
    {
        return $this->admits > 1 ? 'Guest + '.($this->admits - 1) : null;
    }

    public function isValid(): bool
    {
        return $this->state === self::STATE_VALID;
    }

    public function stateLabel(): ?string
    {
        return match ($this->state) {
            self::STATE_CHECKED_IN => $this->checkedInLine,
            self::STATE_CANCELLED => 'This event has been cancelled',
            self::STATE_ENDED => 'This event has ended',
            default => null,
        };
    }

    /**
     * Stable digest of everything printed on the card. The PDF and PNG caches
     * (later phases) key on this, so an edited table, party size or event detail
     * can never serve a stale file.
     */
    public function fingerprint(): string
    {
        return sha1(json_encode([
            $this->eventName, $this->eventTypeLabel, $this->dateLine, $this->timeLine, $this->venue,
            $this->guestName, $this->admits, $this->table, $this->state, $this->checkedInLine,
            $this->coverUrl, $this->theme,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>|null  $given
     * @return array{primary: string, accent: string, background: string}
     */
    private static function resolveTheme(Event $event, ?array $given): array
    {
        $theme = $given;

        if ($theme === null) {
            try {
                $theme = app(InvitationCustomizationService::class)->merge($event)['theme'] ?? [];
            } catch (\Throwable) {
                // An event with no usable template must still get a pass.
                $theme = [];
            }
        }

        $primary = self::hex($theme['primary'] ?? null, self::DEFAULT_PRIMARY);
        $accent = self::hex($theme['accent'] ?? null, self::DEFAULT_ACCENT);
        $background = self::hex($theme['background'] ?? null, self::DEFAULT_BACKGROUND);

        // Header text is drawn in white on the primary colour. A pale palette
        // would make it unreadable, so fall back to the platform navy.
        if (self::luminance($primary) > 0.55) {
            $primary = self::DEFAULT_PRIMARY;
        }

        return ['primary' => $primary, 'accent' => $accent, 'background' => $background];
    }

    /** Only ever emit a #rrggbb literal — the value lands in an inline style. */
    private static function hex(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : $fallback;
    }

    /** WCAG relative luminance, 0 (black) to 1 (white). */
    private static function luminance(string $hex): float
    {
        $channels = array_map(function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
