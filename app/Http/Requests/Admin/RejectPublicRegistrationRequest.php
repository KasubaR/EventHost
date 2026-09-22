<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RejectPublicRegistrationRequest extends FormRequest
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
            'public_registration_rejection_note' => ['required', 'string', 'max:2000'],
        ];
    }
}
