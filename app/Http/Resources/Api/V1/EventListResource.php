<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Event;
use App\Support\EventAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lighter event shape for GET /api/v1/events (list) and the dashboard's staffing
 * list — no analytics/rsvp_summary, keeping these cheap to paginate.
 *
 * @mixin Event
 */
class EventListResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'event_type' => $this->event_type,
            'event_type_label' => $this->event_type_label,
            'cover_image_url' => $this->cover_image_url,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'event_time' => $this->event_time,
            'product_kind' => $this->product_kind?->value,
            'is_published' => $this->is_published,
            'is_public' => $this->is_public,
            'is_locked' => $this->isLocked(),
            'is_cancelled' => $this->isCancelled(),
            'is_invitation_paused' => $this->isInvitationPaused(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            // Android scanner: keep the camera off without probing confirm when closed.
            'is_check_in_open' => $this->isCheckInOpen(),
            'check_in_closed_reason' => $this->isCheckInOpen() ? null : $this->checkInClosedReason(),
        ];

        if ($this->isTicketed()) {
            $data['ticketing_status'] = [
                'value' => $this->ticketing_status?->value,
                'label' => $this->ticketing_status?->label(),
            ];
        }

        // Slice D: same ability flags as EventResource — the staffing list (this
        // resource's other caller) is exactly where a Manager/Check-in staffer needs
        // them, since none of those events are "theirs" in the ownership sense.
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

        return $data;
    }
}
