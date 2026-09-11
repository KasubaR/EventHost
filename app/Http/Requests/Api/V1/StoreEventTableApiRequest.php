<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\StoreEventTableRequest;

/**
 * API twin of StoreEventTableRequest. authorize() is widened per
 * StoreGuestApiRequest's docblock (Slice C3 plan, design decision #1) AND drops the
 * web request's baked-in canUsePremiumEventTools() tier check (design decision #2) —
 * the controller checks that explicitly instead, so a non-premium owner gets the
 * unified `403 {error: 'premium_required'}` shape (decision #3) instead of a bare
 * AuthorizationException.
 */
class StoreEventTableApiRequest extends StoreEventTableRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
