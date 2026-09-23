<?php

namespace App\Services;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Support\ZambianPhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Handles Twilio inbound WhatsApp messages that are RSVP quick-reply taps on the
 * invitation Content Template (docs/twilio.md). Writes through RsvpSubmissionService
 * so capacity / open gates match the web form.
 */
class WhatsAppInboundRsvpService
{
    public const TYPE_INBOUND = 'guest_rsvp_whatsapp_inbound';

    public const TYPE_INVITATION = 'guest_invitation_whatsapp';

    public const PAYLOAD_ACCEPTED = 'rsvp_accepted';

    public const PAYLOAD_DECLINED = 'rsvp_declined';

    public const PAYLOAD_MAYBE = 'rsvp_maybe';

    public function __construct(
        private readonly RsvpSubmissionService $rsvpSubmission,
        private readonly CommunicationService $communication,
        private readonly WhatsAppService $whatsApp,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Twilio form fields
     */
    public function handle(array $payload): void
    {
        $messageSid = isset($payload['MessageSid']) ? trim((string) $payload['MessageSid']) : '';
        if ($messageSid === '') {
            return;
        }

        if ($this->alreadyProcessed($messageSid)) {
            return;
        }

        $status = $this->resolveStatus($payload);
        if ($status === null) {
            return;
        }

        $guest = $this->resolveGuest($payload);
        if ($guest === null) {
            return;
        }

        $event = $guest->event;
        if ($event === null) {
            return;
        }

        $fromE164 = $this->fromE164($payload);

        if (! $event->isRsvpOpen()) {
            $this->recordInboundLog($event->id, $guest->id, $messageSid, 'ignored_closed', $status);
            if ($fromE164 !== null) {
                $this->whatsApp->sendText(
                    $fromE164,
                    'RSVP is closed for '.$event->name.'. If you need help, contact the host.'
                );
            }

            return;
        }

        try {
            $rsvp = $this->rsvpSubmission->submit($event, $guest, [
                'status' => $status,
                'attendee_count' => $status === RsvpStatus::Accepted ? 1 : 0,
            ]);
        } catch (ValidationException $e) {
            $this->recordInboundLog($event->id, $guest->id, $messageSid, 'ignored_validation', $status, [
                'errors' => $e->errors(),
            ]);
            if ($fromE164 !== null) {
                $message = $this->isCapacityError($e)
                    ? 'Sorry, this event is full. Open your invitation link if you need to change a previous response.'
                    : 'We could not save your RSVP. Please use your invitation link to reply.';
                $this->whatsApp->sendText($fromE164, $message);
            }

            return;
        }

        $this->recordInboundLog($event->id, $guest->id, $messageSid, 'processed', $status, [
            'rsvp_id' => $rsvp->id,
        ]);

        $this->communication->dispatchRsvpNotifications($event, $guest, $rsvp);

        if ($fromE164 !== null) {
            $this->sendConfirmation($fromE164, $event, $guest, $rsvp, $status);
        }
    }

    private function sendConfirmation(
        string $toE164,
        Event $event,
        Guest $guest,
        Rsvp $rsvp,
        RsvpStatus $status,
    ): void {
        $caption = $this->confirmationCaption($event, $guest, $status);

        if ($status === RsvpStatus::Accepted && $guest->hasEntryPassFor($rsvp, $event)) {
            $pngUrl = $guest->entryPassPngUrl();
            if (is_string($pngUrl) && $pngUrl !== '') {
                $caption .= "\n\nYour entry pass QR is attached — show it at the door.";
                $this->whatsApp->sendMedia($toE164, $pngUrl, $caption);

                return;
            }
        }

        $this->whatsApp->sendText($toE164, $caption);
    }

    private function confirmationCaption(Event $event, Guest $guest, RsvpStatus $status): string
    {
        $name = filled($guest->name) ? $guest->name : 'Guest';
        $date = $event->event_date?->format('j F Y') ?? '';
        $time = $event->hasStartTime() ? Carbon::parse($event->event_time)->format('H:i') : 'TBA';
        $venue = filled($event->venue) ? $event->venue : 'Venue TBA';
        $link = $guest->personalRsvpUrl() ?? '';

        $lead = match ($status) {
            RsvpStatus::Accepted => "Thank you, {$name}! 🎉\n\nYour RSVP for {$event->name}\nhas been confirmed.",
            RsvpStatus::Declined => "Thanks, {$name}.\n\nWe've recorded that you can't make {$event->name}.",
            RsvpStatus::Maybe => "Thanks, {$name}.\n\nWe've noted you're unsure about {$event->name}.",
        };

        $lines = [
            $lead,
            '',
            '📅 '.$date,
            '🕐 '.$time,
            '📍 '.$venue,
        ];

        if ($status === RsvpStatus::Accepted) {
            $lines[] = '';
            $lines[] = 'We look forward to seeing you!';
        }

        if ($link !== '') {
            $lines[] = '';
            $lines[] = 'Need a plus-one or to change details?';
            $lines[] = $link;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveStatus(array $payload): ?RsvpStatus
    {
        $candidates = [
            isset($payload['ButtonPayload']) ? trim((string) $payload['ButtonPayload']) : '',
            isset($payload['ButtonText']) ? trim((string) $payload['ButtonText']) : '',
            isset($payload['Body']) ? trim((string) $payload['Body']) : '',
        ];

        foreach ($candidates as $raw) {
            if ($raw === '') {
                continue;
            }

            $normalized = strtolower($raw);

            if (in_array($normalized, [self::PAYLOAD_ACCEPTED, 'yes, i\'ll attend', 'yes'], true)) {
                return RsvpStatus::Accepted;
            }
            if (in_array($normalized, [self::PAYLOAD_DECLINED, 'no, i can\'t attend', 'no'], true)) {
                return RsvpStatus::Declined;
            }
            if (in_array($normalized, [self::PAYLOAD_MAYBE, 'i\'ll let you know', 'maybe'], true)) {
                return RsvpStatus::Maybe;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveGuest(array $payload): ?Guest
    {
        $repliedSid = isset($payload['OriginalRepliedMessageSid'])
            ? trim((string) $payload['OriginalRepliedMessageSid'])
            : '';

        if ($repliedSid !== '') {
            $log = NotificationLog::query()
                ->where('provider_message_id', $repliedSid)
                ->where('type', self::TYPE_INVITATION)
                ->where('status', NotificationLog::STATUS_SENT)
                ->whereNotNull('guest_id')
                ->latest('id')
                ->first();

            if ($log?->guest_id) {
                return Guest::query()->with('event')->find($log->guest_id);
            }
        }

        $e164 = $this->fromE164($payload);
        if ($e164 === null) {
            return null;
        }

        $recentLogs = NotificationLog::query()
            ->where('channel', 'whatsapp')
            ->where('type', self::TYPE_INVITATION)
            ->where('status', NotificationLog::STATUS_SENT)
            ->whereNotNull('guest_id')
            ->where('created_at', '>=', now()->subDays(30))
            ->with('guest')
            ->latest('id')
            ->limit(200)
            ->get();

        /** @var Collection<int, Guest> $matches */
        $matches = $recentLogs
            ->map(fn (NotificationLog $log) => $log->guest)
            ->filter(fn ($guest) => $guest instanceof Guest)
            ->filter(fn (Guest $guest) => ZambianPhone::toE164($guest->phone) === $e164)
            ->unique('id')
            ->values();

        if ($matches->count() !== 1) {
            if ($matches->count() > 1) {
                report(new \RuntimeException(
                    'Ambiguous WhatsApp RSVP phone match for '.$e164.' ('.$matches->count().' guests).'
                ));
            }

            return null;
        }

        $guest = $matches->first();
        $guest->loadMissing('event');

        return $guest;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fromE164(array $payload): ?string
    {
        $from = isset($payload['From']) ? trim((string) $payload['From']) : '';
        if ($from === '') {
            return null;
        }

        if (str_starts_with(strtolower($from), 'whatsapp:')) {
            $from = substr($from, strlen('whatsapp:'));
        }

        return ZambianPhone::toE164($from);
    }

    private function alreadyProcessed(string $messageSid): bool
    {
        return NotificationLog::query()
            ->where('provider_message_id', $messageSid)
            ->where('type', self::TYPE_INBOUND)
            ->whereIn('status', [NotificationLog::STATUS_PENDING, NotificationLog::STATUS_SENT])
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function recordInboundLog(
        int $eventId,
        int $guestId,
        string $messageSid,
        string $outcome,
        RsvpStatus $status,
        ?array $meta = null,
    ): void {
        NotificationLog::query()->create([
            'event_id' => $eventId,
            'guest_id' => $guestId,
            'channel' => 'whatsapp',
            'type' => self::TYPE_INBOUND,
            'status' => $outcome === 'processed' ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED,
            'provider_message_id' => $messageSid,
            'sent_at' => $outcome === 'processed' ? now() : null,
            'meta' => array_merge([
                'outcome' => $outcome,
                'rsvp_status' => $status->value,
            ], $meta ?? []),
        ]);
    }

    private function isCapacityError(ValidationException $e): bool
    {
        $errors = $e->errors();

        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                if (is_string($message) && (
                    str_contains(strtolower($message), 'full')
                    || str_contains(strtolower($message), 'guest limit')
                )) {
                    return true;
                }
            }
        }

        return false;
    }
}
