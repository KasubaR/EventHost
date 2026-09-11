<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/events/{slug}/tickets — field list matches what the web ticket picker
 * (events/tickets/purchase.blade.php) shows per type. `available_quantity` and
 * `is_purchasable` call the model's own methods verbatim rather than re-deriving
 * capacity math client-side.
 *
 * @mixin \App\Models\TicketType
 */
class TicketTypeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'badge_color' => $this->badge_color,
            'price' => (string) $this->price,
            'image_url' => $this->image_url,
            'min_per_order' => $this->min_per_order,
            'max_per_order' => $this->max_per_order,
            'sales_starts_at' => $this->sales_starts_at?->toIso8601String(),
            'sales_ends_at' => $this->sales_ends_at?->toIso8601String(),
            // null = unlimited, same semantics as TicketType::availableQuantity() itself.
            'available_quantity' => $this->availableQuantity(),
            'is_purchasable' => $this->isPurchasable(),
        ];
    }
}
