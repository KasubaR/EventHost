<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\GuestBulkActionRequest;

/**
 * API twin of GuestBulkActionRequest — see StoreGuestApiRequest's docblock for the
 * authorize()-widening rationale (Slice C3 plan, design decision #1).
 */
class GuestBulkActionApiRequest extends GuestBulkActionRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
