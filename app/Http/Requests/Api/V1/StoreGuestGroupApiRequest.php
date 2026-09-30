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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'seat_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
        ]);
    }
}
