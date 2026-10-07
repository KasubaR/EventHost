<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use App\Support\TicketCapacity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin changing an event's total capacity at any point, including after
 * ticket sales are approved. The total still cannot drop below what the
 * event's ticket types already hand out (see TicketCapacity::totalProblem()).
 */
class UpdateEventTicketCapacityRequest extends FormRequest
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
            'ticket_capacity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $event = $this->route('event');

            if (! $event instanceof Event || $validator->errors()->has('ticket_capacity')) {
                return;
            }

            $problem = TicketCapacity::totalProblem($event, (int) $this->input('ticket_capacity'));

            if ($problem !== null) {
                $validator->errors()->add('ticket_capacity', $problem);
            }
        });
    }
}
