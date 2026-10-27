<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestEdit;
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
    public function update(Event $event, Guest $guest, array $validated, ?int $actorUserId = null): array
    {
        return DB::transaction(function () use ($event, $guest, $validated, $actorUserId): array {
            // The lock every RSVP write takes, then the guest row, so an edit and a guest's own submit serialise.
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            /** @var Guest $current */
            $current = Guest::query()->whereKey($guest->id)->lockForUpdate()->firstOrFail();

            // Someone saved this guest after the form was opened: refuse, with the host's edits still on screen. The form request
            // checked this before field rules; this repeats it under the lock. No version sent (an API client, an old tab) means last write wins.
            if (isset($validated['guest_version']) && $validated['guest_version'] !== $current->editVersion()) {
                throw ValidationException::withMessages(['guest_version' => [self::STALE_MESSAGE]]);
            }

            $before = self::snapshot($current);
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
            $overPool = false;
            $newGroupId = $data['guest_group_id'] !== null ? (int) $data['guest_group_id'] : null;

            if ($newGroupId !== ($current->guest_group_id !== null ? (int) $current->guest_group_id : null)) {
                if ($newGroupId !== null) {
                    $target = GuestGroup::query()->where('event_id', $event->id)->find($newGroupId);
                    $refusal = $target !== null ? self::poolRefusal($target, $current) : null;

                    if ($refusal !== null) {
                        if (! ($validated['allow_over_pool'] ?? false)) {
                            throw ValidationException::withMessages(['guest_group_id' => [$refusal]]);
                        }

                        $overPool = true;
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

            // What the host changed, for the guest's edit history.
            $current->unsetRelation('group')->unsetRelation('eventTable');
            $changes = [];
            foreach (self::snapshot($current->refresh()) as $field => $value) {
                if ($value !== $before[$field]) {
                    $changes[$field] = [$before[$field], $value];
                }
            }
            if ($linkReplaced) {
                $changes['link_replaced'] = true;
            }
            if ($invitationReset) {
                $changes['invitation_reset'] = true;
            }
            if ($overPool && isset($changes['group'])) {
                $changes['over_pool'] = true;
            }
            GuestEdit::record($current, $actorUserId, $changes);

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
    public function moveManyToGroup(Event $event, array $guestIds, ?int $groupId, ?int $actorUserId = null): array
    {
        return DB::transaction(function () use ($event, $guestIds, $groupId, $actorUserId): array {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $group = $groupId !== null ? GuestGroup::query()->where('event_id', $event->id)->find($groupId) : null;

            $moved = 0;
            $skipped = 0;
            $reason = null;

            Guest::query()
                ->where('event_id', $event->id)
                ->whereIn('id', $guestIds)
                ->with(['rsvp', 'group'])
                ->lockForUpdate()
                ->get()
                ->each(function (Guest $guest) use ($group, $groupId, $actorUserId, &$moved, &$skipped, &$reason): void {
                    if (($guest->guest_group_id !== null ? (int) $guest->guest_group_id : null) !== $groupId) {
                        $refusal = $group !== null ? self::poolRefusal($group, $guest) : null;

                        if ($refusal !== null) {
                            $skipped++;
                            $reason ??= str_replace(' Tick "Allow over the pool" to move them anyway.', ' Move them from their own page to go over the pool.', $refusal);

                            return;
                        }

                        $was = $guest->group?->name;
                        $guest->forceFill(['guest_group_id' => $groupId, 'group_link_joined_at' => null])->save();
                        $guest->unsetRelation('group');
                        GuestEdit::record($guest, $actorUserId, ['group' => [$was, $guest->load('group')->group?->name]]);
                    }

                    $moved++;
                });

            return ['moved' => $moved, 'skipped' => $skipped, 'reason' => $reason];
        });
    }

    public const STALE_MESSAGE = 'This guest was changed somewhere else since you opened this page, so your edits were not saved. Open the latest version and make them again.';

    /**
     * The fields the edit history tracks, as the host sees them (group and table by name).
     *
     * @return array{name: string, email: string|null, phone: string|null, group: string|null, table: string|null, plus_one: bool}
     */
    public static function snapshot(Guest $guest): array
    {
        $guest->loadMissing(['group', 'eventTable']);

        return [
            'name' => (string) $guest->name,
            'email' => $guest->email,
            'phone' => $guest->phone,
            'group' => $guest->group?->name,
            'table' => $guest->eventTable?->label,
            'plus_one' => (bool) $guest->plus_one_allowed,
        ];
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
            // An undelivered row too: "Email bounced" belongs to the old address, not the new one.
            ->where(fn ($query) => $query->whereNotNull('idempotency_key')
                ->orWhereIn('delivery_status', [...NotificationLog::EMAIL_UNDELIVERED_STATUSES, ...NotificationLog::UNDELIVERED_STATUSES]))
            ->get()
            ->each(function (NotificationLog $log): void {
                $log->forceFill([
                    'idempotency_key' => null,
                    'meta' => array_merge($log->meta ?? [], ['superseded' => 'contact_change']),
                ])->save();
            });
    }
}
