<?php

namespace App\Support;

use App\Models\Event;
use App\Models\InvitationTemplate;

/**
 * Host-side notices about an event's invitation layout, rendered by <x-invitation-template-notice>
 * on the edit and event pages. Each case is one a host would otherwise never learn about: guests
 * see nothing, a retired layout, a plan that no longer covers the layout, or a layout made for a
 * different event type. None of them blocks anything except publishing (Event::invitationTemplatePublishBlocker()).
 */
final class InvitationTemplateNotices
{
    /**
     * @return list<array{tone: 'warn'|'info', icon: string, message: string, link: ?string, link_label: ?string}>
     */
    public static function for(Event $event): array
    {
        if ($event->isTicketed()) {
            return [];
        }

        $chooseUrl = route('events.choose-template', $event);

        if ($event->invitation_template_id === null) {
            // The edit page already prompts for a layout on a draft; a live event needs the louder version.
            return $event->is_published ? [[
                'tone' => 'warn',
                'icon' => 'fa-triangle-exclamation',
                'message' => 'This invitation has no layout, so guests see “Invitation unavailable” and cannot RSVP from it.',
                'link' => $chooseUrl,
                'link_label' => 'Choose a layout',
            ]] : [];
        }

        $template = InvitationTemplate::query()->with('categories')->find($event->invitation_template_id);
        if ($template === null) {
            // The id points at a layout that no longer exists. Guests get "Invitation unavailable" and
            // publishing is refused until a new one is chosen (Event::invitationTemplatePublishBlocker()).
            return [[
                'tone' => 'warn',
                'icon' => 'fa-triangle-exclamation',
                'message' => $event->is_published
                    ? 'The layout this invitation used was removed, so guests see “Invitation unavailable” and cannot RSVP from it.'
                    : 'The layout this invitation used was removed. Choose another layout before publishing.',
                'link' => $chooseUrl,
                'link_label' => 'Choose a layout',
            ]];
        }

        $notices = [];

        if (! $template->is_active) {
            $notices[] = [
                'tone' => 'warn',
                'icon' => 'fa-box-archive',
                'message' => $event->is_published
                    ? "{$template->name} is no longer offered. Guests still see it and you can keep editing it, but once you switch to another layout you cannot switch back."
                    : "{$template->name} is no longer offered. Choose another layout before publishing.",
                'link' => $chooseUrl,
                'link_label' => 'Choose a replacement',
            ];
        } else {
            $owner = $event->user;
            if ($owner !== null && $owner->isActive() && ! $owner->canUseInvitationTemplate($template)) {
                $tier = $template->requiredTier()->label();
                $notices[] = [
                    'tone' => 'info',
                    'icon' => 'fa-lock',
                    'message' => "Your plan no longer includes {$template->name}. Guests still see it and you can keep editing it. If you switch to another layout, you will need {$tier} to come back to it.",
                    'link' => BillingPlan::checkoutUrlForTier($template->requiredTier()),
                    'link_label' => "Upgrade to {$tier}",
                ];
            }
        }

        if (! $template->isDesignedFor($event->event_type)) {
            $designedFor = $template->categories
                ->filter(fn ($category) => isset(Event::CATEGORY_SLUG_TO_TYPE[$category->slug]))
                ->pluck('name')
                ->join(', ', ' and ');

            $notices[] = [
                'tone' => 'info',
                'icon' => 'fa-circle-info',
                'message' => "{$template->name} was made for {$designedFor} events. It works for any type, so check that its captions and section wording suit your {$event->event_type_label}.",
                'link' => null,
                'link_label' => null,
            ];
        }

        return $notices;
    }
}
