<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EventStaff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET/POST/PATCH /api/v1/host/events/{event}/staff (Slice D) — ticketed events
 * only, owner-only (App\Policies\EventStaffPolicy never grants Manager). `name`
 * uses EventStaff::displayName(): the real account's name once accepted, the name
 * the host entered while still pending, or the email as a last resort.
 *
 * @mixin EventStaff
 */
class EventStaffResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->displayName(),
            'email' => $this->email,
            'role' => [
                'value' => $this->role->value,
                'label' => $this->role->label(),
            ],
            'is_pending' => $this->isPending(),
            'is_expired' => $this->isExpired(),
            'invited_by' => $this->inviter?->name,
            'invited_at' => $this->created_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
        ];
    }
}
