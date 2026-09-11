<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared by GET /api/v1/events/{slug}/gallery and .../gallery/feed — the web gallery page
 * and its feed() JSON never surface anything beyond these three fields (confirmed against
 * gallery/show.blade.php and public/js/event-gallery.js), so this resource doesn't either.
 *
 * @mixin \App\Models\EventPhoto
 */
class EventPhotoResource extends JsonResource
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
            'uploader_name' => $this->uploader_name,
        ];
    }
}
