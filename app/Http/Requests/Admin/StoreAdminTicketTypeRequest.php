<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
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
            'badge_color' => $this->badge_color === '' || $this->badge_color === null
                ? TicketType::DEFAULT_BADGE_COLOR
                : $this->badge_color,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'badge_color' => ['required', Rule::in(array_keys(TicketType::BADGE_COLORS))],
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

    public function event(): Event
    {
        $event = $this->route('event');

        abort_unless($event instanceof Event && $event->isTicketed(), 404);

        return $event;
    }
}
