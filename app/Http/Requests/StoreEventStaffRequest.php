<?php

namespace App\Http\Requests;

use App\Enums\EventStaffRole;
use App\Models\Event;
use App\Models\EventStaff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && $this->user()?->can('manage', [EventStaff::class, $event]) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::enum(EventStaffRole::class)],
        ];
    }
}
