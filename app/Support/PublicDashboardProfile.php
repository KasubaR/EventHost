<?php

namespace App\Support;

use App\Enums\EventProductKind;
use App\Models\Event;
use Illuminate\Support\Collection;

/**
 * Decides what the public overview (/public-dashboard) shows, from the events
 * the host actually has. Not one dashboard per event type: a small map from
 * product kind to widget groups, plus per-type wording.
 *
 * To change what a kind shows, edit KIND_WIDGETS. To reword a type's dashboard,
 * add an entry to TYPE_LABELS — any key it omits falls back to DEFAULT_LABELS.
 */
final class PublicDashboardProfile
{
    /** Widget groups each product kind switches on. */
    public const KIND_WIDGETS = [
        'ticketed' => ['tickets', 'revenue'],
        'free_registration' => ['registrations'],
    ];

    public const DEFAULT_LABELS = [
        'registered' => 'Registrations',
        'headcount' => 'Expected headcount',
        'daily' => 'Daily registrations',
        'by_event' => 'Registrations by event',
        'row_registered' => 'registered',
        'row_expected' => 'expected',
    ];

    /** @var array<string, array<string, string>> */
    public const TYPE_LABELS = [
        'church' => [
            'registered' => 'Attendees',
            'headcount' => 'Expected attendance',
            'daily' => 'Daily sign-ups',
            'by_event' => 'Attendance by event',
            'row_registered' => 'attending',
        ],
        'funeral' => [
            'registered' => 'Attendees',
            'headcount' => 'Expected attendance',
            'daily' => 'Daily sign-ups',
            'by_event' => 'Attendance by event',
            'row_registered' => 'attending',
        ],
    ];

    /**
     * @param  Collection<int, Event>  $events  the host's public-audience events
     * @return array{widgets: list<string>, labels: array<string, string>}
     */
    public static function forEvents(Collection $events): array
    {
        $kinds = $events
            ->map(fn (Event $e): string => $e->product_kind === EventProductKind::Ticketed ? 'ticketed' : 'free_registration')
            ->unique();

        $widgets = $kinds
            ->flatMap(fn (string $kind): array => self::KIND_WIDGETS[$kind])
            ->unique()
            ->values()
            ->all();

        return [
            'widgets' => $widgets,
            'labels' => self::labelsFor($events),
        ];
    }

    /**
     * Wording follows the type of the free-registration events, but only when
     * they all share one — a mixed set of types reads best with neutral words.
     *
     * @param  Collection<int, Event>  $events
     * @return array<string, string>
     */
    private static function labelsFor(Collection $events): array
    {
        $types = $events
            ->filter(fn (Event $e): bool => $e->product_kind !== EventProductKind::Ticketed)
            ->pluck('event_type')
            ->unique();

        $overrides = $types->count() === 1 ? (self::TYPE_LABELS[$types->first()] ?? []) : [];

        return array_merge(self::DEFAULT_LABELS, $overrides);
    }
}
