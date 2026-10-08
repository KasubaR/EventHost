<?php

namespace App\Rules;

use App\Support\GuestPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One phone number, Zambian or written with its country code. The wording comes from GuestPhone, which the
 * spreadsheet import uses too, so a host sees the same reason on the form and in the import report.
 */
class GuestPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(GuestPhone::CHARACTERS_MESSAGE);

            return;
        }

        if (($problem = GuestPhone::problem($value)) !== null) {
            $fail($problem);
        }
    }
}
