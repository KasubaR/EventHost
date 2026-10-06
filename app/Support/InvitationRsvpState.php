<?php

namespace App\Support;

/**
 * Whether the invitation page actually has an RSVP form on it, as opposed to a banner (closed, ended, or "your host will send you a
 * personal link"). A button that jumps to the RSVP section is only worth showing in the first case; the three branches here mirror
 * the ones in events/invitations/sections/rsvp.blade.php, so change them together.
 */
final class InvitationRsvpState
{
    public static function formShown(bool $rsvpOpen, bool $hasGuest, bool $isPreview, bool $rsvpPublicAvailable, bool $hasSlug): bool
    {
        if (! $rsvpOpen) {
            return false;
        }

        return ($hasGuest && ! $isPreview) || $isPreview || ($rsvpPublicAvailable && $hasSlug);
    }
}
