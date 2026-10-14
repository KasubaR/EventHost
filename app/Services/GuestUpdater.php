<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\NotificationLog;
use App\Support\GuestPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A host editing a guest (web form and API). One place that saves the edit under the event's row lock and knows what a change of
 * contact means: reminder state, the "invitation sent" flag and the personal link all belong to the *address or number a message
 * went to*, so when that changes they must not carry over to the new one. plans/guest-editing-gaps.md Phases 1 and 2.
 *
 * - **New email** (different, and neither side empty): the reminder markers, the guest's "stop reminder emails" choice and the
 *   idempotency keys of their email log rows are cleared, so a reminder can reach the new address and a stranger's old unsubscribe
 *   does not silence the real guest.
 * - **New phone** (different number by `GuestPhone::key()`): the WhatsApp event-reminder markers and the WhatsApp/SMS log keys are cleared.
 * - Either one marks the invitation **not sent** (it has not been sent to the new contact), unless the host says they already told them.
 * - Clearing a contact, or adding one where there was none, resets nothing: there was nothing to reach, or nothing new is reached.
 *
 * - **A group move** into a group with a seat pool counts the seats the guest holds (an accepted answer the host has not rejected, pending
 *   included) against the pool and refuses an overshoot unless the host ticks "allow over the pool". A move out of a pool, or into a group
 *   with none, needs no check. The host's move also clears `group_link_joined_at`: it marks someone who signed up through a group link, and a
 *   guest the host moved by hand is not one (plans/guest-editing-gaps.md Phase 3).
 *
 * The log rows are kept for audit; only their unique idempotency key is released (a `sent` row with a key blocks a repeat send).
 */
class GuestUpdater
{
    /**
     * @param  array<string, mixed>  $validated  UpdateGuestRequest::validated()
     * @return array{
     *     guest: Guest,
     *     email_changed: bool,
     *     phone_changed: bool,
     *     invitation_reset: bool,
     *     link_replaced: bool,
     *     old_link_still_works: bool,
     *     plus_one_switched_off: bool,
     *     plus_one_switched_on: bool,
     * }
     */
    public function update(Event $event, Guest $guest, array $validated): array
    {
        return DB::transaction(function () use ($event, $guest, $validated): array {
            // The lock every RSVP write takes, then the guest row, so an edit and a guest's own submit serialise.
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            /** @var Guest $current */
            $current = Guest::query()->whereKey($guest->id)->lockForUpdate()->firstOrFail();

            $oldEmail = $current->email;
            $oldPhone = $current->phone;
            $hadHistory = $current->invitation_sent || $current->rsvp()->exists();

            $data = [
                'guest_group_id' => $validated['guest_group_id'] ?? null,
                'event_table_id' => $validated['event_table_id'] ?? null,
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'plus_one_allowed' => $validated['plus_one_allowed'] ?? false,
            ];

            $emailChanged = self::emailChanged($oldEmail, $data['email']);
            $phoneChanged = self::phoneChanged($oldPhone, $data['phone']);
            $linkReplaced = ($validated['regenerate_invitation_token'] ?? false) === true;

            if ($linkReplaced) {
                $data['invitation_token'] = Str::random(48);
            }

            $plusOneSwitchedOff = $current->plus_one_allowed && ! $data['plus_one_allowed'];
            $plusOneSwitchedOn = ! $current->plus_one_allowed && $data['plus_one_allowed'];

            // A move to another group: check the target's seat pool, and the guest is no longer "a link joiner".
            $newGroupId = $data['guest_group_id'] !== null ? (int) $data['guest_group_id'] : null;

            if ($newGroupId !== ($current->guest_group_id !== null ? (int) $current->guest_group_id : null)) {
                if ($newGroupId !== null && ! ($validated['allow_over_pool'] ?? false)) {
                    $target = GuestGroup::query()->where('event_id', $event->id)->find($newGroupId);
                    $refusal = $target !== null ? self::poolRefusal($target, $current) : null;

                    if ($refusal !== null) {
                        throw ValidationException::withMessages(['guest_group_id' => [$refusal]]);
                    }
                }

                $data['group_link_joined_at'] = null;
            }

            $current->fill($data);

            if ($emailChanged) {
                $current->forceFill([
                    'rsvp_reminders_sent' => [],
                    'email_reminders_stopped_at' => null,
                ]);
            }

            if ($phoneChanged) {
                $current->forceFill(['whatsapp_event_reminders_sent' => []]);
            }

            $invitationReset = false;
            $keepSent = (bool) ($validated['keep_invitation_sent'] ?? false);

            if (($emailChanged || $phoneChanged) && ! $keepSent && $current->invitation_sent) {
                $current->invitation_sent = false;
                $current->invitation_sent_at = null;
                $invitationReset = true;
            }

            // The host's explicit "mark as sent now" wins over the reset.
            if (($validated['mark_invitation_sent'] ?? false) === true) {
                $current->invitation_sent = true;
                $current->invitation_sent_at = now();
                $invitationReset = false;
            }

            $current->save();

            if ($emailChanged) {
                self::releaseLogKeys($current, ['email']);
            }

            if ($phoneChanged) {
                self::releaseLogKeys($current, ['whatsapp', 'sms']);
            }

            // The old address can still open the link when the email moved on and the link was not replaced. Only worth saying
            // when something was ever sent to it: an RSVP or an invitation.
            $oldLinkStillWorks = ! $linkReplaced
                && $hadHistory
                && $current->invitation_token !== null
                && is_string($oldEmail) && $oldEmail !== ''
                && strtolower(trim($oldEmail)) !== strtolower(trim((string) $current->email));

            return [
                'guest' => $current,
                'email_changed' => $emailChanged,
                'phone_changed' => $phoneChanged,
                'invitation_reset' => $invitationReset,
                'link_replaced' => $linkReplaced,
                'old_link_still_works' => $oldLinkStillWorks,
                'plus_one_switched_off' => $plusOneSwitchedOff,
                'plus_one_switched_on' => $plusOneSwitchedOn,
            ];
        });
    }

    /**
     * Why $guest cannot join $group's seat pool, or null when they can (the group has no pool, the guest holds no seats, or it fits).
     * Counted live: the seats everyone else in the group holds, plus the ones this guest holds. Pending requests hold seats.
     */
    public static function poolRefusal(GuestGroup $group, Guest $guest): ?string
    {
        if (! $group->hasSeatPool()) {
            return null;
        }

        $guest->loadMissing('rsvp');
        $held = $guest->rsvp?->heldSeats() ?? 0;

        if ($held === 0) {
            return null;
        }

        $left = $group->seatsRemaining($guest->id);

        if ($held <= $left) {
            return null;
        }

        $holds = $held === 1 ? '1 seat' : "{$held} seats";

        return $left === 0
            ? "{$group->name} has no seats left, and {$guest->name} holds {$holds}. Tick \"Allow over the pool\" to move them anyway."
            : "Only {$left} ".($left === 1 ? 'seat is' : 'seats are')." left in {$group->name}, and {$guest->name} holds {$holds}. Tick \"Allow over the pool\" to move them anyway.";
    }

    /**
     * The bulk move behind "Assign group" (web and API): every selected guest is checked in turn against the live pool, so the first ones
     * in can fill it and the rest are skipped, never over-filled. A move that takes no seats, or goes to a group with no pool, always
     * happens. Returns how many were assigned, how many were skipped and the first reason.
     *
     * @param  list<int>  $guestIds
     * @return array{moved: int, skipped: int, reason: string|null}
     */
    public function moveManyToGroup(Event $event, array $guestIds, ?int $groupId): array
    {
        return DB::transaction(function () use ($event, $guestIds, $groupId): array {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $group = $groupId !== null ? GuestGroup::query()->where('event_id', $event->id)->find($groupId) : null;

            $moved = 0;
            $skipped = 0;
            $reason = null;

            Guest::query()
                ->where('event_id', $event->id)
                ->whereIn('id', $guestIds)
                ->with('rsvp')
                ->lockForUpdate()
                ->get()
                ->each(function (Guest $guest) use ($group, $groupId, &$moved, &$skipped, &$reason): void {
                    if (($guest->guest_group_id !== null ? (int) $guest->guest_group_id : null) !== $groupId) {
                        $refusal = $group !== null ? self::poolRefusal($group, $guest) : null;

                        if ($refusal !== null) {
                            $skipped++;
                            $reason ??= str_replace(' Tick "Allow over the pool" to move them anyway.', ' Move them from their own page to go over the pool.', $refusal);

                            return;
                        }

                        $guest->forceFill(['guest_group_id' => $groupId, 'group_link_joined_at' => null])->save();
                    }

                    $moved++;
                });

            return ['moved' => $moved, 'skipped' => $skipped, 'reason' => $reason];
        });
    }

    /** A different email, with an address on both sides. Case and padding do not count. */
    public static function emailChanged(?string $old, ?string $new): bool
    {
        if (! is_string($old) || trim($old) === '' || ! is_string($new) || trim($new) === '') {
            return false;
        }

        return strtolower(trim($old)) !== strtolower(trim($new));
    }

    /** A different number, with a phone on both sides. `0971234567` and `+260 97 123 4567` are the same number. */
    public static function phoneChanged(?string $old, ?string $new): bool
    {
        if (! is_string($old) || trim($old) === '' || ! is_string($new) || trim($new) === '') {
            return false;
        }

        return (GuestPhone::key($old) ?? trim($old)) !== (GuestPhone::key($new) ?? trim($new));
    }

    /**
     * Frees the unique idempotency key of this guest's log rows on the given channels, so the next send to the new contact is not
     * mistaken for a repeat. The rows stay (marked superseded) as the record of what went to the old contact.
     *
     * @param  list<string>  $channels
     */
    private static function releaseLogKeys(Guest $guest, array $channels): void
    {
        NotificationLog::query()
            ->where('guest_id', $guest->id)
            ->whereIn('channel', $channels)
            ->whereNotNull('idempotency_key')
            ->get()
            ->each(function (NotificationLog $log): void {
                $log->forceFill([
                    'idempotency_key' => null,
                    'meta' => array_merge($log->meta ?? [], ['superseded' => 'contact_change']),
                ])->save();
            });
    }
}
