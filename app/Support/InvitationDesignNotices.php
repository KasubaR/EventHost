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

        if (($merged['restored_from_previous'] ?? false) === true) {
            $notices[] = [
                'tone' => 'warn',
                'icon' => 'fa-clock-rotate-left',
                'message' => 'Your saved invitation design could not be read, so guests are seeing the version from before your last design save. Check the design and save it to keep it.',
                'link' => $editUrl,
                'link_label' => $editUrl !== null ? 'Review the design' : null,
            ];
        }

        $variant = InvitationLayoutVariant::normalize($merged['layout_variant'] ?? null);
        $detailsBlocked = in_array(InvitationSections::DETAILS, InvitationLayoutVariant::blockedSections($variant), true);

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
}
