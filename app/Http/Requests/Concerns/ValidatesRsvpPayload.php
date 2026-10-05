<?php

namespace App\Http\Requests\Concerns;

use App\Enums\RsvpStatus;
use App\Models\Event;
use Closure;
use Illuminate\Validation\Rule;

trait ValidatesRsvpPayload
{
    /**
     * @return array<string, mixed>
     */
    protected function rsvpFieldRules(Event $event, bool $plusOneAllowed, int $heldSeats = 0): array
    {
        return [
            'status' => ['required', Rule::enum(RsvpStatus::class)],
            'attendee_count' => [
                'required',
                'integer',
                'min:0',
                function (string $attribute, mixed $value, Closure $fail) use ($event, $plusOneAllowed, $heldSeats): void {
                    $status = RsvpStatus::tryFrom((string) $this->input('status'));
                    if ($status === null) {
                        return;
                    }
                    // A seat already confirmed stays valid if plus-ones were switched off since, so an
                    // unchanged re-submit does not fail. RsvpSubmissionService applies the same floor.
                    $max = max(($event->allow_plus_one && $plusOneAllowed) ? 2 : 1, $heldSeats);
                    $intVal = (int) $value;
                    if ($status === RsvpStatus::Accepted) {
                        if ($intVal < 1 || $intVal > $max) {
                            $fail('Choose between 1 and '.$max.' attendee(s) for your response.');
                        }
                    } elseif ($intVal !== 0) {
                        $fail('Attendee count must be zero for this response.');
                    }
                },
            ],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{status:RsvpStatus,attendee_count:int,message?:string|null}
     */
    public function validatedRsvpPayload(): array
    {
        /** @var array{status:string,attendee_count:int,message?:string|null} $data */
        $data = $this->validated();

        return [
            'status' => RsvpStatus::from($data['status']),
            'attendee_count' => (int) $data['attendee_count'],
            'message' => $data['message'] ?? null,
        ];
    }
}
