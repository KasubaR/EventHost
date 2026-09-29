<?php

namespace App\Support;

/**
 * Links for the section nav drawn above Pro invitation layouts
 * (InvitationLayoutVariant::hasSectionNav()). Built from the sections the renderer
 * actually output, so a section that rendered nothing (no story, empty programme)
 * never gets a dead link. Countdown has no label and is never linked.
 */
final class InvitationSectionNav
{
    private const LABELS = [
        InvitationSections::HERO => 'Home',
        InvitationSections::DESCRIPTION => 'Invitation',
        InvitationSections::STORY => 'Story',
        InvitationSections::DETAILS => 'Details',
        InvitationSections::SCHEDULE => 'Programme',
        InvitationSections::GALLERY => 'Gallery',
        InvitationSections::RSVP => 'RSVP',
    ];

    /** Per-layout wording, matching what each template's own section headings say. */
    private const LAYOUT_LABELS = [
        InvitationLayoutVariant::BOTANICAL_GRADUATION => [
            InvitationSections::DESCRIPTION => 'Note',
            InvitationSections::SCHEDULE => 'The Day',
        ],
        InvitationLayoutVariant::BEAUTY_FOR_ASHES => [
            InvitationSections::GALLERY => 'Speakers',
            InvitationSections::STORY => 'Message',
            InvitationSections::SCHEDULE => 'Schedule',
            InvitationSections::DESCRIPTION => 'Contact',
        ],
    ];

    /**
     * Prefixed so it never collides with ids the layouts already use (#rsvp, #home, #save-the-date).
     */
    public static function anchorId(string $type): string
    {
        return 'inv-'.$type;
    }

    /**
     * @param  list<string>  $renderedTypes  section types in page order
     * @return list<array{id: string, label: string}>
     */
    public static function items(?string $variant, array $renderedTypes, bool $ticketed = false): array
    {
        $variant = InvitationLayoutVariant::normalize($variant);

        if (! InvitationLayoutVariant::hasSectionNav($variant)) {
            return [];
        }

        $labels = array_merge(self::LABELS, self::LAYOUT_LABELS[$variant] ?? []);

        if ($ticketed) {
            $labels[InvitationSections::RSVP] = 'Tickets';
        }

        $items = [];
        foreach (array_unique($renderedTypes) as $type) {
            if (isset($labels[$type])) {
                $items[] = ['id' => self::anchorId($type), 'label' => $labels[$type]];
            }
        }

        return count($items) > 1 ? $items : [];
    }
}
