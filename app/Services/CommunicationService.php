<?php

namespace App\Services;

use App\Models\ContributionPayment;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\ContributionReceiptNotification;
use App\Notifications\EventUpdatedNotification;
use App\Notifications\NewContributionReceivedNotification;
use App\Notifications\NewRsvpReceivedNotification;
use App\Notifications\RsvpConfirmationNotification;
use App\Notifications\RsvpReminderNotification;
use App\Support\ZambianPhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class CommunicationService
{
    public function __construct(
        private readonly SmsService $smsService,
        private readonly WhatsAppService $whatsAppService,
    ) {}

    public function sendRsvpReminder(Event $event, Guest $guest, int $daysUntilDeadline, ?string $idempotencyKey = null): void
    {
        if (! $event->ownerCanSendAutomatedReminders()) {
            return;
        }

        if (! is_string($guest->email) || $guest->email === '') {
            return;
        }

        $meta = ['days_until_deadline' => $daysUntilDeadline];
        $log = $this->startLog($event, $guest, 'email', 'rsvp_reminder', $idempotencyKey, $meta);
        if ($log === null) {
            return;
        }

        try {
            Notification::route('mail', $guest->email)
                ->notify(new RsvpReminderNotification($event, $guest, $daysUntilDeadline));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    public function sendRsvpConfirmation(Event $event, Guest $guest, Rsvp $rsvp): void
    {
        if (! is_string($guest->email) || $guest->email === '') {
            return;
        }

        $log = $this->startLog($event, $guest, 'email', 'rsvp_confirmation', null, null);
        if ($log === null) {
            return;
        }

        try {
            Notification::route('mail', $guest->email)
                ->notify(new RsvpConfirmationNotification($event, $guest, $rsvp));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    public function notifyHostNewRsvp(User $host, Event $event, Guest $guest, Rsvp $rsvp): void
    {
        // Either channel wanted is enough to bother notifying at all — the
        // notification's own via() (Slice E) independently decides which
        // channel(s) actually fire, so a host with email off but push on
        // (or vice versa) still gets something instead of nothing.
        $wantsEmail = (bool) ($host->notification_preferences['email_rsvp_updates'] ?? true);
        $wantsPush = (bool) ($host->notification_preferences['push_rsvp_updates'] ?? true);

        if (! $wantsEmail && ! $wantsPush) {
            return;
        }

        $log = $this->startLog($event, $guest, 'email', 'host_new_rsvp', null, ['host_user_id' => $host->id]);
        if ($log === null) {
            return;
        }

        try {
            $host->notify(new NewRsvpReceivedNotification($event, $guest, $rsvp));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    /**
     * One receipt per completed installment, not just the final one — a
     * contributor paying in parts gets a receipt each time. No-op when the
     * contributor didn't give an email (that field is optional).
     */
    public function sendContributionReceipt(EventContribution $contribution, ContributionPayment $payment): void
    {
        if (! is_string($contribution->contributor_email) || trim($contribution->contributor_email) === '') {
            return;
        }

        $event = $contribution->event()->firstOrFail();
        $log = $this->startLog($event, $contribution->guest, 'email', 'contribution_receipt', null, [
            'event_contribution_id' => $contribution->id,
            'contribution_payment_id' => $payment->id,
        ]);
        if ($log === null) {
            return;
        }

        try {
            Notification::route('mail', $contribution->contributor_email)
                ->notify(new ContributionReceiptNotification($contribution, $payment));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    /**
     * Same trigger point as sendContributionReceipt() — every completed
     * installment, not just the final one.
     */
    public function notifyHostNewContribution(User $host, EventContribution $contribution, ContributionPayment $payment): void
    {
        if (! $host->wantsEmailContributionUpdates()) {
            return;
        }

        $event = $contribution->event()->firstOrFail();
        $log = $this->startLog($event, $contribution->guest, 'email', 'host_new_contribution', null, [
            'host_user_id' => $host->id,
            'event_contribution_id' => $contribution->id,
        ]);
        if ($log === null) {
            return;
        }

        try {
            $host->notify(new NewContributionReceivedNotification($contribution, $payment));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    public function sendEventUpdate(Event $event, Guest $guest, string $updateMessage): void
    {
        if (! is_string($guest->email) || $guest->email === '') {
            return;
        }

        $log = $this->startLog($event, $guest, 'email', 'event_update', null, null);
        if ($log === null) {
            return;
        }

        try {
            Notification::route('mail', $guest->email)
                ->notify(new EventUpdatedNotification($event, $guest, $updateMessage));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    public function sendSmsUpdate(Event $event, Guest $guest, string $updateMessage): void
    {
        if (! (bool) config('communications.sms.enabled', false)) {
            return;
        }

        if (! is_string($guest->phone) || trim($guest->phone) === '') {
            return;
        }

        $log = $this->startLog($event, $guest, 'sms', 'event_update_sms', null, null);
        if ($log === null) {
            return;
        }

        try {
            $result = $this->smsService->send($guest->phone, $updateMessage);
            $status = $result['status'] === 'sent' ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED;
            $log->forceFill([
                'status' => $status,
                'provider_message_id' => $result['provider_message_id'],
                'response' => $result['response'],
                'sent_at' => $status === NotificationLog::STATUS_SENT ? now() : null,
            ])->save();
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    /**
     * Server-initiated WhatsApp send via an approved template — see
     * plans/whatsapp-invitations.md. Distinct from the free App\Support\WhatsAppInviteLink deeplink
     * (which stays available regardless of whether Twilio is configured); this one is a no-op until
     * communications.whatsapp.enabled and the phone both check out, same guarded shape as
     * sendSmsUpdate() above. Returns an outcome string (rather than void, like the fire-and-forget
     * methods above) because this is a direct, user-triggered button click that needs an immediate
     * flash message, not a background/scheduled send GuestController never inspects the result of.
     *
     * @return 'sent'|'disabled'|'invalid_phone'|'rate_limited'|'failed'
     */
    public function sendWhatsAppInvitation(Event $event, Guest $guest): string
    {
        if (! (bool) config('communications.whatsapp.enabled', false)) {
            return 'disabled';
        }

        $toE164 = ZambianPhone::toE164($guest->phone);
        if ($toE164 === null || $guest->invitation_token === null) {
            return 'invalid_phone';
        }

        // Event-scoped guard on top of the per-user 'guest-whatsapp-send' route throttle — each
        // send costs real money, so one event can't blow through Twilio's/Meta's own rate limits
        // via many individual clicks even though no single click is throttled.
        $cap = max(1, (int) config('communications.whatsapp.hourly_cap_per_event', 100));
        $sentLastHour = NotificationLog::query()
            ->where('event_id', $event->id)
            ->where('channel', 'whatsapp')
            ->where('status', NotificationLog::STATUS_SENT)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($sentLastHour >= $cap) {
            return 'rate_limited';
        }

        // No idempotency key is passed here, so this never actually returns null — same posture
        // as sendSmsUpdate() above; kept for parity with startLog()'s shared signature.
        $log = $this->startLog($event, $guest, 'whatsapp', 'guest_invitation_whatsapp', null, null);
        if ($log === null) {
            return 'failed';
        }

        try {
            $result = $this->whatsAppService->sendTemplate(
                $toE164,
                (string) config('services.twilio.invitation_content_sid'),
                [
                    '1' => $event->name,
                    '2' => $event->event_date?->format('j F Y') ?? '',
                    '3' => $event->hasStartTime() ? Carbon::parse($event->event_time)->format('H:i') : '',
                    '4' => filled($event->venue) ? $event->venue : 'Venue TBA',
                    '5' => $guest->personalRsvpUrl(),
                ]
            );
            $status = $result['status'] === 'sent' ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED;
            $log->forceFill([
                'status' => $status,
                'provider_message_id' => $result['provider_message_id'],
                'response' => $result['response'],
                'sent_at' => $status === NotificationLog::STATUS_SENT ? now() : null,
            ])->save();

            if ($status === NotificationLog::STATUS_SENT) {
                $guest->forceFill([
                    'invitation_sent' => true,
                    'invitation_sent_at' => now(),
                ])->save();

                return 'sent';
            }

            return 'failed';
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function startLog(
        Event $event,
        ?Guest $guest,
        string $channel,
        string $type,
        ?string $idempotencyKey,
        ?array $meta
    ): ?NotificationLog {
        if ($idempotencyKey !== null) {
            $existing = NotificationLog::query()
                ->where('idempotency_key', $idempotencyKey)
                ->whereIn('status', [NotificationLog::STATUS_PENDING, NotificationLog::STATUS_SENT])
                ->first();

            if ($existing !== null) {
                return null;
            }
        }

        return NotificationLog::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guest?->id,
            'channel' => $channel,
            'type' => $type,
            'status' => NotificationLog::STATUS_PENDING,
            'idempotency_key' => $idempotencyKey,
            'meta' => $meta,
        ]);
    }

    private function markSent(NotificationLog $log): void
    {
        $log->forceFill([
            'status' => NotificationLog::STATUS_SENT,
            'sent_at' => now(),
            'response' => null,
        ])->save();
    }

    private function markFailed(NotificationLog $log, \Throwable $e): void
    {
        $log->forceFill([
            'status' => NotificationLog::STATUS_FAILED,
            'response' => substr($e->getMessage(), 0, 65535),
        ])->save();
    }
}
