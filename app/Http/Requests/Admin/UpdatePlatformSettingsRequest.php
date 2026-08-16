<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:120'],
            'whatsapp_default_message' => ['nullable', 'string', 'max:2000'],
            'ticketing_commission_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'ticketing_cancellation_fee_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }
}
