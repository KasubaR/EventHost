<?php

namespace App\Http\Requests\Concerns;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Rules\AttendeeCount;
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
            // `bail`: one sentence per mistake, not one per rule. AttendeeCount does the whole check (whole number,
            // at least 1, at most the guest's maximum) and only for an acceptance. plans/rsvp-attendance.md Phase 1.
            'attendee_count' => [
                'bail',
                'required_if:status,'.RsvpStatus::Accepted->value,
                'nullable',
                new AttendeeCount(
                    // A seat already confirmed stays valid if plus-ones were switched off since, so an
                    // unchanged re-submit does not fail. RsvpSubmissionService applies the same floor.
                    max(($event->allow_plus_one && $plusOneAllowed) ? 2 : 1, $heldSeats),
                    function (): bool {
                        // A tampered `status[]=x` posts an array; the enum rule already fails it.
                        $rawStatus = $this->input('status');

                        return is_string($rawStatus) && RsvpStatus::tryFrom($rawStatus) === RsvpStatus::Accepted;
                    },
                ),
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
            'attendee_count.required_if' => 'Please choose how many people are coming.',
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
            'attendee_count' => $status === RsvpStatus::Accepted ? (AttendeeCount::parse($data['attendee_count'] ?? null) ?? 0) : 0,
            'message' => $data['message'] ?? null,
        ];
    }
}
