<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Event;
use App\Models\EventTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventTable
 */
class TableResource extends JsonResource
{
    public static $wrap = null;

    /**
     * $event is passed explicitly for the same reason as GuestResource — avoids a
     * lazy per-table relation load when building many of these at once.
     */
    public function __construct(EventTable $resource, private readonly Event $event)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'code' => $this->code,
            'sort_order' => $this->sort_order,
            'photos_count' => $this->photos_count,
            // Same premium gate the web qr()/qrSheet() actions use. See the Slice
            // C3 plan, design decision #4 — a JSON URL field instead of a binary
            // SVG endpoint, since the client renders its own QR from this string.
            'qr_payload_url' => $this->event->ownerHasPremiumEventTools() ? $this->publicUploadUrl() : null,
        ];
    }
}
