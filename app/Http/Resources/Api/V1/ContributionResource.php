<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/contributions/{reference} — full pledge/status view. The reference itself
 * is the bearer secret (same trust model as RSVP tokens / ticket order references), so
 * exposing contributor phone/email here is not a new disclosure surface.
 *
 * @mixin \App\Models\EventContribution
 */
class ContributionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment = $this->payments->first();

        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'is_completed' => $this->isCompleted(),
            'event' => new PublicEventListResource($this->event),
            'contributor' => [
                'name' => $this->contributor_name,
                'phone' => $this->contributor_phone,
                'email' => $this->contributor_email,
            ],
            'currency' => $this->currency,
            'target_amount' => (string) $this->target_amount,
            'amount_paid' => (string) $this->amount_paid,
            // remainingAmount() is a computed float, not a decimal-cast attribute —
            // number_format, not (string) round(...): a whole-number result would
            // otherwise stringify as "60" instead of "60.00" (same trap class as
            // TicketHoldResource's subtotal/total in Slice B2).
            'remaining_amount' => number_format($this->remainingAmount(), 2, '.', ''),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'bank_transfer_enabled' => (bool) config('services.lenco.bank_transfer_enabled', true),
            'latest_payment' => $payment === null ? null : [
                'status' => $payment->status,
                'failure_reason' => $payment->failure_reason,
            ],
        ];
    }
}
