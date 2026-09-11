<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\StoreTicketCheckoutRequest;

/**
 * API twin of StoreTicketCheckoutRequest — a real subclass, not a copy, since the
 * parent's authorize() is already unconditionally true and its rules() have zero
 * session/CSRF/View coupling. Adds the one field the API needs that the web request
 * never did: cart_id, which replaces web's session-based TicketCart for identifying
 * which held reservations this checkout applies to.
 */
class StoreTicketCheckoutApiRequest extends StoreTicketCheckoutRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'cart_id' => ['required', 'string', 'uuid'],
        ]);
    }
}
