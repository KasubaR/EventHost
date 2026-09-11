<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /api/v1/events/{slug}/table/{code}/photos — upload acknowledgment, matches the
 * web controller's existing wantsJson() shape exactly (id, thumbnail_url, status). No
 * uploaded_at: the client already knows "now" at the moment it gets this reply.
 *
 * @mixin \App\Models\EventPhoto
 */
class TablePhotoResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'thumbnail_url' => $this->thumbnail_url,
            'status' => $this->status->value,
        ];
    }
}
