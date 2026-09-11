<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/discover — lighter than PublicEventResource on purpose: no customization merge
 * per row. Field list matches resources/views/events/partials/public-event-card.blade.php,
 * the card shared by the homepage strip and /discover.
 *
 * @mixin \App\Models\Event
 */
class PublicEventListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'event_type' => $this->event_type,
            'event_type_label' => $this->event_type_label,
            'cover_image_url' => $this->cover_image_url,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'event_time' => $this->event_time,
            'venue' => $this->venue,
            'location_name' => $this->location_name,
        ];
    }
}
