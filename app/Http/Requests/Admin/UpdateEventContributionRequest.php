<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventContributionRequest extends FormRequest
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
            'contribution_enabled' => ['required', 'boolean'],
            'contribution_amount' => ['required_if:contribution_enabled,1', 'nullable', 'numeric', 'min:1', 'max:999999.99'],
        ];
    }
}
