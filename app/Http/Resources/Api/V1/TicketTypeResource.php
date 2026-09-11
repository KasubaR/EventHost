<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TicketType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/events/{slug}/tickets (guest picker) and GET/POST/PATCH
 * /api/v1/host/events/{event}/ticket-types (Slice D, host setup) — one shape for
 * both. `is_active` and `sort_order` are meaningless to a guest (inactive types
 * never reach the guest endpoint's query, and sort order is applied server-side
 * before either caller sees the list) but harmless to include, so this stays one
 * resource instead of forking a host-only twin. `available_quantity` and
 * `is_purchasable` call the model's own methods verbatim rather than re-deriving
 * capacity math client-side.
 *
 * @mixin TicketType
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
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
