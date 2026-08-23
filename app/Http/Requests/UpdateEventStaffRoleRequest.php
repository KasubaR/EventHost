<?php

namespace App\Http\Requests;

use App\Enums\EventStaffRole;
use App\Models\EventStaff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventStaffRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $eventStaff = $this->route('eventStaff');

        return $eventStaff instanceof EventStaff && $this->user()?->can('update', $eventStaff) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(EventStaffRole::class)],
        ];
    }
}
