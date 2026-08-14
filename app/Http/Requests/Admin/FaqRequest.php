<?php

namespace App\Http\Requests\Admin;

use App\Models\Faq;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FaqRequest extends FormRequest
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
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:2000'],
            'placement' => ['required', Rule::in(array_keys(Faq::PLACEMENTS))],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_published' => ['required', 'boolean'],
        ];
    }
}
