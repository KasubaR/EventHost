<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Support\GuestPhone;
use App\Support\WhatsAppInviteLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The free wa.me share link: the host opens a chat on their own phone and presses send there. Nothing is sent by us and
 * nothing is billed, so the strongest signal we get is "the host opened the chat", which is what recordOpened() stores.
 * The paid server-initiated send is CommunicationService::sendWhatsAppInvitation(); keep the two apart.
 * Plan: plans/whatsapp-sharing-gaps.md.
 */
class WhatsAppShareService
{
    public const LOG_CHANNEL = 'whatsapp_link';

    public const LOG_TYPE = 'guest_invitation_whatsapp_link';

    public const QUEUE_SESSION_KEY = 'whatsapp_share_queue';

    /** Why no guest of this event should be sent their link right now (a draft, a closed RSVP), or null. */
    public function eventBlockedReason(Event $event): ?string
    {
        if (! $event->is_published) {
            return 'Publish the event first. Until then, guest links show "Invitation unavailable".';
        }

        return $event->rsvpClosedReason();
    }

    /** Why this guest's chat cannot be opened, or null. */
    public function guestProblem(Guest $guest): ?string
    {
        if ($guest->invitation_token === null) {
            return 'No personal invitation link.';
        }

        if (blank($guest->phone)) {
            return 'No phone number. Copy their invitation message and send it another way.';
        }

        if (GuestPhone::isZambianLandline($guest->phone)) {
            return 'Landline: WhatsApp cannot reach it.';
        }

        if (GuestPhone::whatsAppDigits($guest->phone) === null) {
            return 'This number cannot be opened in WhatsApp. Edit the guest to fix it.';
        }

        return null;
    }

    public function message(Event $event, Guest $guest): ?string
    {
        $rsvpUrl = $guest->personalRsvpUrl();

        return $rsvpUrl === null ? null : WhatsAppInviteLink::invitationMessage($guest->name, $event->name, $rsvpUrl);
    }

    /** The wa.me chat link with the invitation message filled in, or null when the guest cannot be reached by it. */
    public function url(Event $event, Guest $guest): ?string
    {
        $message = $this->guestProblem($guest) === null ? $this->message($event, $guest) : null;

        return $message === null ? null : WhatsAppInviteLink::url($guest->phone, $message);
    }

    /** The host opened this guest's chat: mark the invitation sent and keep a record of when. */
    public function recordOpened(Event $event, Guest $guest, ?int $userId): void
    {
        DB::transaction(function () use ($event, $guest, $userId): void {
            $locked = Guest::query()->whereKey($guest->id)->lockForUpdate()->firstOrFail();
            $now = now();

            $locked->forceFill([
                'invitation_sent' => true,
                'invitation_sent_at' => $now,
            ])->save();

            NotificationLog::query()->create([
                'event_id' => $event->id,
                'guest_id' => $locked->id,
                'channel' => self::LOG_CHANNEL,
                'type' => self::LOG_TYPE,
                'status' => NotificationLog::STATUS_SENT,
                'sent_at' => $now,
                'meta' => ['user_id' => $userId],
            ]);
        });
    }

    /**
     * When the host last opened each guest's chat.
     *
     * @param  list<int>  $guestIds
     * @return array<int, Carbon>
     */
    public function lastOpened(Event $event, array $guestIds, ?Carbon $since = null): array
    {
        if ($guestIds === []) {
            return [];
        }

        return NotificationLog::query()
            ->where('event_id', $event->id)
            ->where('type', self::LOG_TYPE)
            ->whereIn('guest_id', $guestIds)
            ->when($since !== null, fn ($q) => $q->where('sent_at', '>=', $since))
            ->groupBy('guest_id')
            ->selectRaw('guest_id, max(sent_at) as last_opened_at')
            ->pluck('last_opened_at', 'guest_id')
            ->map(fn ($value) => Carbon::parse($value))
            ->all();
    }
}
