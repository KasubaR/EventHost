<?php

namespace App\Http\Requests;

use App\Enums\CommissionMode;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventTicketingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            && $event->isTicketed()
            && $this->user()?->can('update', $event) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'commission_mode' => ['required', Rule::enum(CommissionMode::class)],
        ];
    }
}
