<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && $this->user()?->can('update', $event) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'description' => $this->description === '' ? null : $this->description,
            'quantity' => $this->quantity === '' || $this->quantity === null ? null : $this->quantity,
            'sales_starts_at' => $this->sales_starts_at === '' ? null : $this->sales_starts_at,
            'sales_ends_at' => $this->sales_ends_at === '' ? null : $this->sales_ends_at,
            'terms' => $this->terms === '' ? null : $this->terms,
            'sort_order' => $this->sort_order === '' || $this->sort_order === null ? 0 : $this->sort_order,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'sales_starts_at' => ['nullable', 'date'],
            'sales_ends_at' => ['nullable', 'date', 'after_or_equal:sales_starts_at'],
            'min_per_order' => ['required', 'integer', 'min:1', 'max:100'],
            'max_per_order' => ['required', 'integer', 'min:1', 'max:100', 'gte:min_per_order'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }
}
