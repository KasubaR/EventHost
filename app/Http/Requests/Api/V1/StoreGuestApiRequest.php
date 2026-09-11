<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\StoreGuestRequest;

/**
 * API twin of StoreGuestRequest — inherits rules()/prepareForValidation() unchanged.
 * authorize() is deliberately widened: the web request is owner-only, narrower than
 * GuestPolicy::update() (owner OR accepted Manager staff), which the controller itself
 * checks explicitly instead. See the Slice C3 plan, design decision #1.
 */
class StoreGuestApiRequest extends StoreGuestRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
