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
            // Only an acceptance carries a seat count. A Declined or Maybe answer ignores whatever was sent
            // (a form without JavaScript still posts the default 1, which used to be rejected); the service
            // stores 0 for those. plans/invitation-page-resilience.md Phase 2.
            'attendee_count' => [
                'required_if:status,'.RsvpStatus::Accepted->value,
                'nullable',
                'integer',
                'min:0',
                function (string $attribute, mixed $value, Closure $fail) use ($event, $plusOneAllowed, $heldSeats): void {
                    // A tampered `status[]=x` posts an array; the enum rule already fails it, so just stop here.
                    $rawStatus = $this->input('status');
                    $status = is_string($rawStatus) ? RsvpStatus::tryFrom($rawStatus) : null;
                    if ($status === null) {
                        return;
                    }
                    // A seat already confirmed stays valid if plus-ones were switched off since, so an
                    // unchanged re-submit does not fail. RsvpSubmissionService applies the same floor.
                    $max = max(($event->allow_plus_one && $plusOneAllowed) ? 2 : 1, $heldSeats);
                    $intVal = (int) $value;
                    if ($status === RsvpStatus::Accepted && ($intVal < 1 || $intVal > $max)) {
                        $fail('Choose between 1 and '.$max.' attendee(s) for your response.');
                    }
                },
            ],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * A failed submit returns the guest to the form, not the top of the page: the form sits at #rsvp,
     * often far below a hero, so without the fragment the error is off-screen and the page looks unchanged.
     */
    protected function getRedirectUrl(): string
    {
        $url = parent::getRedirectUrl();

        return strtok($url, '#').'#rsvp';
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Please choose whether you can come.',
            'attendee_count.required_if' => 'Please say how many people are coming.',
            'attendee_count.integer' => 'Please say how many people are coming.',
        ];
    }

    /**
     * @return array{status:RsvpStatus,attendee_count:int,message?:string|null}
     */
    public function validatedRsvpPayload(): array
    {
        /** @var array{status:string,attendee_count:int,message?:string|null} $data */
        $data = $this->validated();

        $status = RsvpStatus::from($data['status']);

        return [
            'status' => $status,
            'attendee_count' => $status === RsvpStatus::Accepted ? (int) ($data['attendee_count'] ?? 0) : 0,
            'message' => $data['message'] ?? null,
        ];
    }
}
