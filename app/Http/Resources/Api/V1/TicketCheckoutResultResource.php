<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TicketOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /api/v1/events/{slug}/tickets/checkout — same shape TicketCheckoutService
 * returns to the web controller. The controller wrapping this resource MUST force
 * ->response()->setStatusCode(200): $order is always a freshly TicketOrder::create()'d
 * row (never firstOrCreate), so JsonResource's automatic wasRecentlyCreated-based
 * status calculation would otherwise report 201 on every single call, not just the
 * first — see the Slice B2 plan, design decision #6.
 */
class TicketCheckoutResultResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(TicketOrder $order, private readonly array $result)
    {
        parent::__construct($order);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'success' => true,
            'status' => $this->resource->status->value,
            'order_reference' => $this->resource->order_reference,
            'payment_instructions' => $this->result['paymentInstructions'] ?? null,
            'bank_details' => $this->result['bankDetails'] ?? null,
            'payment_url' => $this->result['paymentUrl'] ?? null,
        ];
    }
}
