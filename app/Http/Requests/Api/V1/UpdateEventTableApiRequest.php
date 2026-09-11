<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\UpdateEventTableRequest;

/**
 * API twin of UpdateEventTableRequest — see StoreEventTableApiRequest's docblock
 * for the authorize()-widening and dropped-tier-check rationale.
 */
class UpdateEventTableApiRequest extends UpdateEventTableRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
