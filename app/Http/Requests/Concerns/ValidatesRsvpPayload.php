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
    protected function rsvpFieldRules(Event $event, bool $plusOneAllowed): array
    {
        return [
            'status' => ['required', Rule::enum(RsvpStatus::class)],
            'attendee_count' => [
                'required',
                'integer',
                'min:0',
                function (string $attribute, mixed $value, Closure $fail) use ($event, $plusOneAllowed): void {
                    $status = RsvpStatus::tryFrom((string) $this->input('status'));
                    if ($status === null) {
                        return;
                    }
                    $max = ($event->allow_plus_one && $plusOneAllowed) ? 2 : 1;
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
            'meal_preference' => ['nullable', 'string', 'max:255'],
            'transportation_note' => ['nullable', 'string', 'max:255'],
            'song_request' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{status:RsvpStatus,attendee_count:int,message?:string|null,meal_preference?:string|null,transportation_note?:string|null,song_request?:string|null}
     */
    public function validatedRsvpPayload(): array
    {
        /** @var array{status:string,attendee_count:int,message?:string|null,meal_preference?:string|null,transportation_note?:string|null,song_request?:string|null} $data */
        $data = $this->validated();

        return [
            'status' => RsvpStatus::from($data['status']),
            'attendee_count' => (int) $data['attendee_count'],
            'message' => $data['message'] ?? null,
            'meal_preference' => $data['meal_preference'] ?? null,
            'transportation_note' => $data['transportation_note'] ?? null,
            'song_request' => $data['song_request'] ?? null,
        ];
    }
}
