<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\UpdateGuestGroupRequest;

/**
 * API twin of UpdateGuestGroupRequest — see StoreGuestApiRequest's docblock for the
 * authorize()-widening rationale (Slice C3 plan, design decision #1).
 */
class UpdateGuestGroupApiRequest extends UpdateGuestGroupRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
