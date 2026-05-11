<?php

namespace App\Support;

final class InvitationSections
{
    public const HERO = 'hero';

    public const DETAILS = 'details';

    public const DESCRIPTION = 'description';

    public const STORY = 'story';

    public const SCHEDULE = 'schedule';

    public const RSVP = 'rsvp';

    public const COUNTDOWN = 'countdown';

    public const GALLERY = 'gallery';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::HERO,
            self::DETAILS,
            self::DESCRIPTION,
            self::STORY,
            self::SCHEDULE,
            self::RSVP,
            self::COUNTDOWN,
            self::GALLERY,
        ];
    }
}
