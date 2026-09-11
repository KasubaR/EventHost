<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EventStaffLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET/POST /api/v1/host/events/{event}/checkin/links (Slice D). `scanner_url`
 * is EventStaffLink::scannerUrl() — a no-login browser page the app hands to the
 * system share sheet. Deliberately no App Link for it (plans/android-app.md §5.2):
 * the recipient is meant to open it in a plain browser, not this app. The raw
 * `token` is intentionally omitted — nothing on-device needs it once `scanner_url`
 * already carries it.
 *
 * @mixin EventStaffLink
 */
class StaffLinkResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->scanLabel(),
            'scanner_url' => $this->scannerUrl(),
            'is_active' => $this->isActive(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
