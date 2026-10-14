<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Support\GuestPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            ];
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
