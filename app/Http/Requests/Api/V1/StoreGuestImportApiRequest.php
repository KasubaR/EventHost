<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\StoreGuestImportRequest;

/**
 * API twin of StoreGuestImportRequest — see StoreGuestApiRequest's docblock for the
 * authorize()-widening rationale (Slice C3 plan, design decision #1).
 */
class StoreGuestImportApiRequest extends StoreGuestImportRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
