<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Event;
use App\Support\EventAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full host-facing event object — GET /api/v1/events/{event} and every mutating
 * action's success response. `rsvp_summary`/`analytics`/`contribution_summary` only
 * appear when supplied (show() passes all three; store()/lifecycle actions pass
 * none, so those cheaper responses don't pay for extra count queries).
 *
 * @mixin Event
 */
class EventResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  array<string, mixed>|null  $rsvpSummary
     * @param  array<string, mixed>|null  $analytics
     * @param  array<string, mixed>|null  $contributionSummary
     */
    public function __construct(
        Event $resource,
        private readonly ?array $rsvpSummary = null,
        private readonly ?array $analytics = null,
        private readonly ?array $contributionSummary = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'event_type' => $this->event_type,
            'event_type_label' => $this->event_type_label,
            'product_kind' => $this->product_kind?->value,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'event_time' => $this->event_time,
            'venue' => $this->venue,
            'location_name' => $this->location_name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'cover_image_url' => $this->cover_image_url,
            'is_published' => $this->is_published,
            'is_public' => $this->is_public,
            'is_locked' => $this->isLocked(),
            'is_cancelled' => $this->isCancelled(),
            'is_invitation_paused' => $this->isInvitationPaused(),
            'invitation_paused_at' => $this->invitation_paused_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'rsvp_deadline' => $this->rsvp_deadline?->toIso8601String(),
            'guest_limit' => $this->guest_limit,
            'allow_plus_one' => $this->allow_plus_one,
            'show_guest_list' => $this->show_guest_list,
            'invitation_template_id' => $this->invitation_template_id,
            'accepts_contributions' => $this->acceptsContributions(),
            'contribution_enabled' => $this->contribution_enabled,
            'contribution_amount' => $this->contribution_amount,
            'branding_removed' => $this->branding_removed,
            'invitation_views_count' => $this->invitation_views_count,
            'publish_costs_credit' => ! $this->isTicketed() && ! $this->is_published && ! $this->hasConsumedPublishCredit(),
            'created_at' => $this->created_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];

        // Slice D: lets the app tell an owner apart from an accepted Manager/Check-in
        // staffer for this event without re-deriving EventAccess's ranking client-side —
        // same "ask the server" rule already applied to subscription-tier flags on /me.
        $user = $request->user();
        if ($user !== null) {
            $staffRole = $this->staffRoleFor($user);
            $data['is_owner'] = EventAccess::isOwner($user, $this->resource);
            $data['staff_role'] = $staffRole === null ? null : [
                'value' => $staffRole->value,
                'label' => $staffRole->label(),
            ];
            $data['can_manage'] = EventAccess::canManage($user, $this->resource);
            $data['can_check_in'] = EventAccess::canCheckIn($user, $this->resource);
        }

        if ($this->isTicketed()) {
            $data['ticketing_status'] = [
                'value' => $this->ticketing_status?->value,
                'label' => $this->ticketing_status?->label(),
            ];
        }

        if ($this->rsvpSummary !== null) {
            $data['rsvp_summary'] = $this->rsvpSummary;
        }

        if ($this->analytics !== null) {
            $data['analytics'] = $this->analytics;
        }

        if ($this->contributionSummary !== null) {
            $data['contribution_summary'] = $this->contributionSummary;
        }

        return $data;
    }
}
