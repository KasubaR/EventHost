<?php

namespace App\Http\Requests\Admin;

use App\Enums\CommissionMode;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminEventCommissionRequest extends FormRequest
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
            'commission_mode' => ['required', Rule::enum(CommissionMode::class)],
        ];
    }

    public function event(): Event
    {
        $event = $this->route('event');

        abort_unless($event instanceof Event && $event->isTicketed(), 404);

        return $event;
    }
}
