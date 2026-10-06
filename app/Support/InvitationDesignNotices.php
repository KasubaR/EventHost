<?php

namespace App\Support;

use App\Models\Event;
use App\Services\InvitationCustomizationService;

/**
 * Host-side notices about the saved invitation design, rendered alongside
 * InvitationTemplateNotices by <x-invitation-template-notice> on the edit and event pages.
 * Neither case blocks anything: details may be hidden on purpose, and a restored design
 * is still a design.
 */
final class InvitationDesignNotices
{
    /**
     * @param  array<string, mixed>|null  $merged  InvitationCustomizationService::merge() output, when the caller has it
     * @return list<array{tone: 'warn'|'info', icon: string, message: string, link: ?string, link_label: ?string}>
     */
    public static function for(Event $event, ?array $merged = null, bool $onEditPage = false): array
    {
        if ($event->isTicketed() || $event->invitation_template_id === null) {
            return [];
        }

        try {
            $merged ??= app(InvitationCustomizationService::class)->merge($event);
        } catch (\RuntimeException) {
            return [];
        }

        $editUrl = $onEditPage ? null : route('events.edit', $event);
        $notices = [];

        $nameLength = mb_strlen(trim((string) $event->name));
        if ($nameLength > InvitationTextLength::EXTRA_LONG) {
            $notices[] = [
                'tone' => 'info',
                'icon' => 'fa-text-width',
                'message' => "The event name is {$nameLength} characters long. Guests can read it, but it can look crowded on some layouts and in link previews, which cut it short. Check the preview, and shorten it if it does not look right.",
                'link' => route('events.preview', $event),
                'link_label' => 'Preview the invitation',
            ];
        }

        if (EventPlace::isUnknown($event)) {
            $notices[] = [
                'tone' => 'info',
                'icon' => 'fa-location-dot',
                'message' => 'No venue, location or map pin is set, so guests will see "'.EventPlace::TO_BE_ANNOUNCED_LINE.'". That is fine if it is not decided yet; add it before you send invitations.',
                'link' => ($editUrl ?? '').'#venue',
                'link_label' => 'Add the venue',
            ];
        }

        if (($merged['restored_from_previous'] ?? false) === true) {
            $notices[] = [
                'tone' => 'warn',
                'icon' => 'fa-clock-rotate-left',
                'message' => 'Your saved invitation design could not be read, so guests are seeing the version from before your last design save. Check the design and save it to keep it.',
                'link' => $editUrl,
                'link_label' => $editUrl !== null ? 'Review the design' : null,
            ];
        }

        $missingMedia = InvitationMediaHealth::missing($event);
        if ($missingMedia !== []) {
            $count = count($missingMedia);
            $names = collect($missingMedia)->pluck('label')->unique()->map(fn (string $l) => mb_strtolower($l))->join(', ', ' and ');
            $notices[] = [
                'tone' => 'warn',
                'icon' => 'fa-image',
                'message' => "{$count} ".($count === 1 ? 'file' : 'files').' from your invitation ('.$names.') can no longer be found. Guests do not see '.($count === 1 ? 'it' : 'them').'; the rest of the invitation is unaffected. Upload '.($count === 1 ? 'it' : 'them').' again to bring '.($count === 1 ? 'it' : 'them').' back.',
                'link' => $editUrl,
                'link_label' => $editUrl !== null ? 'Open the design' : null,
            ];
        }

        $variant = InvitationLayoutVariant::normalize($merged['layout_variant'] ?? null);
        $detailsBlocked = in_array(InvitationSections::DETAILS, InvitationLayoutVariant::blockedSections($variant), true);

        if (trim((string) $event->description) === '' && self::showsDescription($merged, $variant)) {
            $notices[] = [
                'tone' => 'info',
                'icon' => 'fa-align-left',
                'message' => 'Your event has no description yet, so the invitation shows a standard line, or leaves that part out, instead of your own words. Add a few lines about the event for your guests.',
                'link' => ($editUrl ?? '').'#description',
                'link_label' => 'Add a description',
            ];
        }

        foreach ($merged['sections'] ?? [] as $row) {
            if (($row['type'] ?? null) === InvitationSections::DETAILS && ! $detailsBlocked && ! ($row['visible'] ?? true)) {
                $notices[] = [
                    'tone' => 'info',
                    'icon' => 'fa-eye-slash',
                    'message' => 'The Event details section is hidden, so guests may not see the venue, time or map links. Check the preview to make sure where and when still show.',
                    'link' => $editUrl !== null ? $editUrl.'#inv-section-sortable-root' : null,
                    'link_label' => $editUrl !== null ? 'Show it again' : null,
                ];
                break;
            }
        }

        return $notices;
    }

    /**
     * Whether the saved design has a visible description section the layout does not block, i.e. somewhere the host's words would go.
     *
     * @param  array<string, mixed>  $merged
     */
    private static function showsDescription(array $merged, string $variant): bool
    {
        if (in_array(InvitationSections::DESCRIPTION, InvitationLayoutVariant::blockedSections($variant), true)) {
            return false;
        }

        foreach ($merged['sections'] ?? [] as $row) {
            if (($row['type'] ?? null) === InvitationSections::DESCRIPTION && ($row['visible'] ?? true)) {
                return true;
            }
        }

        return false;
    }
}
