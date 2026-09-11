<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\UpdateGuestRequest;

/**
 * API twin of UpdateGuestRequest — see StoreGuestApiRequest's docblock for the
 * authorize()-widening rationale (Slice C3 plan, design decision #1).
 */
class UpdateGuestApiRequest extends UpdateGuestRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
