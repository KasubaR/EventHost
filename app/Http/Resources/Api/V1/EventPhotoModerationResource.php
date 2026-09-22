<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EventPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET/PATCH/DELETE /api/v1/host/events/{event}/photos (Slice E) — host
 * moderation shape. Distinct from the guest-facing EventPhotoResource (feed
 * shape: thumbnail + uploader only) — same "two shapes, one model" precedent
 * as Slice D's TicketResource vs TicketManagementResource.
 *
 * @mixin EventPhoto
 */
class EventPhotoModerationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnail_url,
            'uploader_name' => $this->uploader_name,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'table_label' => $this->table?->label,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
