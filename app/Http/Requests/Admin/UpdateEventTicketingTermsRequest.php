<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Blank fields mean "no deal for this event" — clears the override back to
 * the platform default rather than requiring the admin to look the default
 * up and re-enter it. See UpdateEventTicketingTermsRequest::prepareForValidation().
 */
class UpdateEventTicketingTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'commission_percent_override' => $this->commission_percent_override === '' ? null : $this->commission_percent_override,
            'cancellation_fee_percent_override' => $this->cancellation_fee_percent_override === '' ? null : $this->cancellation_fee_percent_override,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'commission_percent_override' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'cancellation_fee_percent_override' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }
}
