<?php

namespace App\Support;

use App\Models\Event;

/**
 * What a layout prints in its invitation text when the host wrote no description. The wedding layouts used to carry a fixed wedding
 * sentence, which read as the host's own words on a birthday, memorial or church invitation (a layout can be chosen for any event
 * type). A wedding keeps the layout's own wedding wording; every other type gets a short neutral line for its type. Nothing here is
 * stored: it is only ever a display default, replaced as soon as the host writes a description.
 */
final class InvitationDescriptionFallback
{
    /** @var array<string, string> */
    private const BY_TYPE = [
        'birthday' => 'Please join us to celebrate. We would love to have you with us.',
        'graduation' => 'Please join us to celebrate this achievement together.',
        'corporate' => 'You are invited to join us. We look forward to seeing you there.',
        'baby_shower' => 'Please join us to celebrate and shower the little one with love.',
        'funeral' => 'You are invited to gather with family and friends to remember and honour a life.',
        'church' => 'You are warmly invited. We would be glad to have you with us.',
    ];

    public const GENERIC = 'You are warmly invited. We hope you can join us.';

    /**
     * The host's description when there is one, else the default for the event's type.
     *
     * @param  string|null  $weddingWording  the layout's own wording, used only for a wedding
     */
    public static function for(Event $event, ?string $weddingWording = null): string
    {
        $written = trim((string) $event->description);
        if ($written !== '') {
            return $written;
        }

        if ($event->event_type === 'wedding' && $weddingWording !== null && $weddingWording !== '') {
            return $weddingWording;
        }

        return self::BY_TYPE[(string) $event->event_type] ?? self::GENERIC;
    }
}
