<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\StoreGuestGroupRequest;

/**
 * API twin of StoreGuestGroupRequest — see StoreGuestApiRequest's docblock for the
 * authorize()-widening rationale (Slice C3 plan, design decision #1).
 */
class StoreGuestGroupApiRequest extends StoreGuestGroupRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
