<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET/POST/PATCH /api/v1/reviews (Slice E). `status`/`is_featured` in
 * update()'s response are the whole point of the resource: editing an
 * approved+featured review resets both (App\Http\Controllers\ReviewController
 * ::update()) — the app must show that consequence, not assume it, so it
 * always reads it back from this same shape rather than a bespoke "edited"
 * flag.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => [
                'id' => $this->event->id,
                'name' => $this->event->name,
                'slug' => $this->event->slug,
            ],
            'rating' => $this->rating,
            'body' => $this->body,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'is_featured' => $this->is_featured,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
