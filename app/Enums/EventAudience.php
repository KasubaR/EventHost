<?php

namespace App\Enums;

/**
 * Who an event is for — the axis that decides which organizer portal it lives
 * in. Orthogonal to EventProductKind (the mechanism: invitation/RSVP vs paid
 * tickets). Plan: plans/public-private-portals.md.
 *
 * Private: a specific invited group, entered by personal invitation link.
 * Public:  a broad audience who discover, register or buy tickets.
 */
enum EventAudience: string
{
    case Private = 'private';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private event',
            self::Public => 'Public event',
        };
    }

    /**
     * Derive the audience from the two legacy flags that decided it before the
     * column existed: ticketed events were always public, and an invitation
     * event was public only when the host ticked "is_public". Used by the
     * backfill and by Event's saving hook while is_public is still an input.
     */
    public static function derive(?EventProductKind $kind, bool $isPublic): self
    {
        return $kind === EventProductKind::Ticketed || $isPublic
            ? self::Public
            : self::Private;
    }
}
