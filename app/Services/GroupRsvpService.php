<?php

namespace App\Services;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * plans/group-rsvp-links.md — a person asks for seats through a group's shared link.
 * They become an ordinary Guest in the group with their own RSVP, held for host approval;
 * the seat pool itself is enforced in RsvpSubmissionService under the event row lock.
 */
class GroupRsvpService
{
    public function __construct(private readonly RsvpSubmissionService $submissions) {}

    /**
     * @param  array{name:string,email:string,phone:string}  $contact
     * @param  array{attendee_count:int,message?:string|null}  $payload
     * @return array{guest: Guest, rsvp: Rsvp}
     */
    public function request(GuestGroup $group, Event $event, array $contact, array $payload): array
    {
        return DB::transaction(function () use ($group, $event, $contact, $payload): array {
            // Same lock RsvpSubmissionService takes, taken first so the state check, the guest
            // insert and the seat count all happen on one serialized view of the event.
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $group->refresh();

            if (app(GroupRsvpResolver::class)->stateFor($group, $event, $group->seatsRemaining()) !== GroupRsvpResolver::OPEN) {
                throw ValidationException::withMessages([
                    'status' => ['This group is no longer taking requests. Please call the host for more information.'],
                ]);
            }

            $guest = Guest::query()
                ->where('event_id', $event->id)
                ->where('email', $contact['email'])
                ->first();

            if ($guest !== null && $guest->guest_group_id !== $group->id) {
                throw ValidationException::withMessages([
                    'email' => ['This email is already on the guest list. Use the personal link you were sent, or call the host.'],
                ]);
            }

            if (Guest::matchingPhone($event, $contact['phone'], ignoreGuestId: $guest?->id) !== null) {
                throw ValidationException::withMessages([
                    'phone' => ['This phone number is already on the guest list. Use the personal link you were sent, or call the host.'],
                ]);
            }

            if ($guest === null) {
                if ($event->hasReachedGuestCapacity()) {
                    throw ValidationException::withMessages([
                        'status' => ['This event\'s guest list is full. Please call the host for more information.'],
                    ]);
                }

                $guest = Guest::query()->create([
                    'event_id' => $event->id,
                    'guest_group_id' => $group->id,
                    'group_link_joined_at' => now(),
                    'name' => $contact['name'],
                    'email' => $contact['email'],
                    'phone' => $contact['phone'],
                    'invitation_token' => Str::random(48),
                    'plus_one_allowed' => (bool) $event->allow_plus_one,
                ]);
            } else {
                $guest->fill(['name' => $contact['name'], 'phone' => $contact['phone']])->save();
            }

            $rsvp = $this->submissions->submit($event, $guest, [
                'status' => RsvpStatus::Accepted,
                'attendee_count' => $payload['attendee_count'],
                'message' => $payload['message'] ?? null,
            ]);

            return ['guest' => $guest, 'rsvp' => $rsvp];
        });
    }
}
