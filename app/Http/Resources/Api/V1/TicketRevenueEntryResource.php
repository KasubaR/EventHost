<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TicketRevenueEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/host/events/{event}/tickets/revenue (Slice D) — one ledger row.
 * Read-only, same as the web Revenue tab: payouts are admin-recorded only.
 *
 * @mixin TicketRevenueEntry
 */
class TicketRevenueEntryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => [
                'value' => $this->type,
                'label' => $this->typeLabel(),
            ],
            'gross_amount' => (string) $this->gross_amount,
            'platform_fee' => (string) $this->platform_fee,
            'host_amount' => (string) $this->host_amount,
            'buyer_total' => $this->buyer_total !== null ? (string) $this->buyer_total : null,
            'order_reference' => $this->order?->order_reference,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
