<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class ApproveTicketingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'agreed_payout_on' => $this->agreed_payout_on === '' ? null : $this->agreed_payout_on,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $event = $this->route('event');
        $minDate = $event instanceof Event && $event->event_date
            ? $event->event_date->format('Y-m-d')
            : 'today';

        return [
            'agreed_payout_on' => ['nullable', 'date', 'after_or_equal:'.$minDate],
        ];
    }
}
