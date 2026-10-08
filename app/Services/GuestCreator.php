<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A guest the host adds by hand (web form and API). The form request already checked the email and phone, but
 * only the email has a unique index behind it: two submits at the same moment could both pass the phone check.
 * Both are re-checked here under the event's row lock, the same lock EventGuestsImport and GroupRsvpService take,
 * so a duplicate is a validation error rather than a second row or a database exception.
 */
class GuestCreator
{
    /**
     * @param  array<string, mixed>  $validated  StoreGuestRequest::validated()
     *
     * @throws ValidationException
     */
    public function create(Event $event, array $validated): Guest
    {
        $markSent = (bool) ($validated['mark_invitation_sent'] ?? false);

        return DB::transaction(function () use ($event, $validated, $markSent): Guest {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $email = $validated['email'] ?? null;
            if ($email !== null && Guest::query()->where('event_id', $event->id)->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
            }

            if (Guest::phoneAlreadyUsed($event, $validated['phone'] ?? null)) {
                throw ValidationException::withMessages(['phone' => 'This phone number is already used by another guest for this event.']);
            }

            return Guest::query()->create([
                'event_id' => $event->id,
                'guest_group_id' => $validated['guest_group_id'] ?? null,
                'event_table_id' => $validated['event_table_id'] ?? null,
                'name' => $validated['name'],
                'email' => $email,
                'phone' => $validated['phone'] ?? null,
                'invitation_token' => Str::random(48),
                'plus_one_allowed' => $validated['plus_one_allowed'] ?? false,
                'invitation_sent' => $markSent,
                'invitation_sent_at' => $markSent ? now() : null,
            ]);
        });
    }
}
