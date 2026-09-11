<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EventContribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /api/v1/events/{slug}/contribute and POST /api/v1/contributions/{reference}/pay —
 * shared shape for both. Always includes `reference`, even for pay() (web's raw pay() JSON
 * omits it since the page already has it in the URL) — one consistent shape either call
 * returns, not a field the client must special-case away.
 *
 * The controller MUST force ->response()->setStatusCode(200): startOrResume() can create a
 * brand-new EventContribution row, the same wasRecentlyCreated-leak shape Slice B1 hit with
 * Guest — left alone, JsonResource's automatic status calculation could report 201.
 */
class ContributionCheckoutResultResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(EventContribution $contribution, private readonly array $result)
    {
        parent::__construct($contribution);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'success' => true,
            'status' => $this->resource->status->value,
            'reference' => $this->resource->reference,
            'payment_instructions' => $this->result['paymentInstructions'] ?? null,
            'bank_details' => $this->result['bankDetails'] ?? null,
            'payment_url' => $this->result['paymentUrl'] ?? null,
        ];
    }
}
