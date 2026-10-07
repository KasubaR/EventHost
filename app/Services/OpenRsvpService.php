<?php

namespace App\Services;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\RsvpChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The open RSVP link (`/e/{slug}/rsvp`): finds or creates the guest and records their answer in ONE
 * transaction. They used to be separate, so a submit that was then refused (closed at the lock, guest
 * limit) or a request that died left behind a guest with no RSVP — eating a private event's guest-list
 * cap, cluttering the host's list, and, for an existing guest, having already overwritten their name and
 * phone. Same shape as GroupRsvpService::request(). plans/rsvp-submission-edge-cases.md Phase 3.
 */
class OpenRsvpService
{
    public function __construct(private readonly RsvpSubmissionService $submissions) {}

    /**
     * @param  array{name:string,email:string,phone?:string|null}  $contact
     * @param  array{status:RsvpStatus,attendee_count:int,message?:string|null}  $payload
     * @return array{guest: Guest, rsvp: Rsvp}
     */
    public function submit(Event $event, array $contact, array $payload, bool $isPrivate, string $channel = RsvpChange::CHANNEL_WEB_OPEN): array
    {
        return DB::transaction(function () use ($event, $contact, $payload, $isPrivate, $channel): array {
            // The lock every submit takes, taken first: the capacity check, the guest write and the RSVP
            // then all happen on one serialized view of the event, so two requests for the same email
            // cannot race on the unique(event_id, email) constraint.
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $guest = Guest::query()
                ->where('event_id', $event->id)
                ->where('email', $contact['email'])
                ->first();

            if ($guest === null) {
                // A private event's plan cap applies to a genuinely new signup only. The request checked
                // this too; repeating it under the lock is what stops a race from passing the cap.
                if ($isPrivate && $locked->hasReachedGuestCapacity()) {
                    throw ValidationException::withMessages([
                        'email' => ['This event\'s guest list is full. Please call the host for more information.'],
                    ]);
                }

                $guest = Guest::query()->create([
                    'event_id' => $event->id,
                    'email' => $contact['email'],
                    'name' => $contact['name'],
                    'phone' => $contact['phone'] ?? null,
                    'invitation_token' => $isPrivate ? Str::random(48) : null,
                    'plus_one_allowed' => $isPrivate && (bool) $locked->allow_plus_one,
                ]);
            } else {
                $data = ['name' => $contact['name'], 'phone' => $contact['phone'] ?? null];

                // A guest who existed beforehand from some other path (e.g. the public form, before this
                // event became private) has no token; back-fill one so the private form's "you get a
                // personal link" promise always holds.
                if ($isPrivate && $guest->invitation_token === null) {
                    $data['invitation_token'] = Str::random(48);
                }

                $guest->fill($data)->save();
            }

            // Throws (closed, guest limit...) roll the guest write above back with it.
            $rsvp = $this->submissions->submit($event, $guest, $payload, channel: $channel);

            return ['guest' => $guest, 'rsvp' => $rsvp];
        });
    }
}
