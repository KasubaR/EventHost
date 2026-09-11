<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TicketPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/host/events/{event}/tickets/payouts (Slice D) — read-only, admin-
 * recorded disbursement. There is no write action anywhere for this on mobile,
 * same as web.
 *
 * @mixin TicketPayout
 */
class TicketPayoutResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (string) $this->amount,
            'paid_on' => $this->paid_on?->toDateString(),
            'note' => $this->note,
            'paid_by' => $this->paidBy?->name,
        ];
    }
}
