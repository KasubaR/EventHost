<?php

namespace App\Services;

use App\Enums\EventAudience;
use App\Enums\RsvpStatus;
use App\Models\ContributionPayment;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\ContributionReceiptNotification;
use App\Notifications\EventUpdatedNotification;
use App\Notifications\GuestEventReminderNotification;
use App\Notifications\HostEventReminderNotification;
use App\Notifications\HostPurgeWarningNotification;
use App\Notifications\NewContributionReceivedNotification;
use App\Notifications\NewRsvpReceivedNotification;
use App\Notifications\RsvpConfirmationNotification;
use App\Notifications\RsvpReminderNotification;
use App\Support\EventReminderBuckets;
use App\Support\WhatsAppEventReminderBuckets;
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

        // The guest turned reminder emails off from the link in one.
        if ($guest->hasStoppedEmailReminders()) {
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
     * Shared by web RSVP, API RSVP, and WhatsApp inbound quick-reply — keep side effects identical.
     */
    public function dispatchRsvpNotifications(Event $event, Guest $guest, Rsvp $rsvp): void
    {
        try {
            $rsvp->loadMissing('guest');

            if (is_string($guest->email) && $guest->email !== '') {
                $this->sendRsvpConfirmation($event, $guest, $rsvp);
            }

            $event->loadMissing('user');
            $host = $event->user;

            if ($host !== null) {
                $this->notifyHostNewRsvp($host, $event, $guest, $rsvp);
            }
        } catch (\Throwable $e) {
            report($e);
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

    /**
     * One digest email telling a host which of their deleted events are about to be
     * permanently removed (plans/event-retention.md §4b). Each event gets its own
     * NotificationLog row, keyed on Event::purgeWarningKey(): that is what makes a
     * re-run a no-op, what lets a restore-then-delete-again be warned about afresh,
     * and what the purge itself checks before it will delete anything. Events already
     * warned about are dropped from the digest. Returns how many were included.
     *
     * @param  iterable<Event>  $events  Deleted events with a purge date, all owned by $host
     */
    public function sendPurgeWarning(User $host, iterable $events): int
    {
        $logs = [];
        $items = [];

        foreach ($events as $event) {
            $purgeAt = $event->scheduledPurgeDate();
            if ($purgeAt === null) {
                continue;
            }

            $log = $this->startLog($event, null, 'email', 'event_purge_warning', $event->purgeWarningKey(), [
                'host_user_id' => $host->id,
                'purge_at' => $purgeAt->toIso8601String(),
            ]);
            if ($log === null) {
                continue;
            }

            $logs[] = $log;
            $items[] = [
                'purge_at' => $purgeAt,
                'name' => (string) $event->name,
                'deleted_on' => $event->deleted_at->format('M j, Y'),
                'purge_on' => $purgeAt->format('M j, Y'),
                'list_url' => route($event->audience === EventAudience::Public ? 'public-events.index' : 'events.index', absolute: true),
            ];
        }

        if ($items === []) {
            return 0;
        }

        // Soonest first, so the subject and the button lead with the most urgent one.
        usort($items, fn (array $a, array $b): int => $a['purge_at'] <=> $b['purge_at']);
        $items = array_map(fn (array $item): array => array_diff_key($item, ['purge_at' => true]), $items);

        try {
            $host->notify(new HostPurgeWarningNotification($items));

            foreach ($logs as $log) {
                $this->markSent($log);
            }
        } catch (\Throwable $e) {
            foreach ($logs as $log) {
                $this->markFailed($log, $e);
            }

            throw $e;
        }

        return count($items);
    }

    /**
     * Scheduled email to the event owner before the event (7 / 1 days). One
     * per event per lead time — the idempotency key makes a re-run of the
     * scheduler, or a second worker, a no-op. Returns whether one was sent.
     */
    public function notifyHostEventReminder(User $host, Event $event, int $daysUntilEvent): bool
    {
        if (! $host->wantsEmailEventReminders()) {
            return false;
        }

        $idempotencyKey = sprintf('host-event-reminder:%d:%d', $event->id, $daysUntilEvent);
        $log = $this->startLog($event, null, 'email', 'host_event_reminder', $idempotencyKey, [
            'host_user_id' => $host->id,
            'days_until_event' => $daysUntilEvent,
        ]);
        if ($log === null) {
            return false;
        }

        try {
            $host->notify(new HostEventReminderNotification($event, $daysUntilEvent));
            $this->markSent($log);

            return true;
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
            // Numbered slots match the Meta/Twilio Quick Reply Content Template
            // (plans/whatsapp-invitations.md / docs/twilio.md): body {{1}}..{{5}} details,
            // {{6}} = full personal RSVP URL for plus-ones; Yes/No/Maybe are template buttons.
            $result = $this->whatsAppService->sendTemplate(
                $toE164,
                (string) config('services.twilio.invitation_content_sid'),
                [
                    '1' => filled($guest->name) ? $guest->name : 'Guest',
                    '2' => $event->name,
                    '3' => $event->event_date?->format('j F Y') ?? '',
                    '4' => $event->hasStartTime() ? Carbon::parse($event->event_time)->format('H:i') : 'TBA',
                    '5' => filled($event->venue) ? $event->venue : 'Venue TBA',
                    // Body footnote for plus-ones / full form — Quick Reply template has no URL button.
                    '6' => (string) $guest->personalRsvpUrl(),
                    // IMAGE header path after the production host (template: https://HOST/{{7}}).
                    '7' => $event->whatsAppInviteHeaderMediaPath(),
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
     * Scheduled WhatsApp reminder for Accepted guests (7 / 1 / 0 days before event_date).
     * Distinct from sendRsvpReminder() which emails non-responders before rsvp_deadline.
     *
     * @return 'sent'|'disabled'|'invalid_phone'|'rate_limited'|'skipped'|'failed'
     */
    public function sendWhatsAppEventReminder(Event $event, Guest $guest, string $bucket): string
    {
        if (! (bool) config('communications.whatsapp.enabled', false)) {
            return 'disabled';
        }

        if (! $event->ownerCanSendAutomatedReminders()) {
            return 'disabled';
        }

        // A cancelled event stays published; the command's scope filters it, this keeps the rule
        // true for any other caller of this method.
        if ($event->isCancelled() || $event->trashed()) {
            return 'skipped';
        }

        if (! WhatsAppEventReminderBuckets::isAllowed($bucket)) {
            return 'skipped';
        }

        /** @var list<string> $already */
        $already = $guest->whatsapp_event_reminders_sent;
        if (in_array($bucket, $already, true)) {
            return 'skipped';
        }

        $guest->loadMissing('rsvp');
        if ($guest->rsvp === null || $guest->rsvp->status !== RsvpStatus::Accepted) {
            return 'skipped';
        }

        $toE164 = ZambianPhone::toE164($guest->phone);
        if ($toE164 === null) {
            return 'invalid_phone';
        }

        $contentSid = (string) config('services.twilio.event_reminder_content_sid', '');
        if ($contentSid === '') {
            return 'disabled';
        }

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

        // The event date is part of the key: a moved event is a new reminder for the new date, and
        // a key without it would treat the 7-day reminder as already sent (plans/guest-email-reminders.md §4.2).
        $idempotencyKey = sprintf(
            'wa-event-reminder:%d:%d:%s:%s',
            $event->id,
            $guest->id,
            $bucket,
            $event->event_date?->format('Y-m-d') ?? 'undated'
        );
        $log = $this->startLog($event, $guest, 'whatsapp', 'guest_event_reminder_whatsapp', $idempotencyKey, [
            'bucket' => $bucket,
        ]);
        if ($log === null) {
            return 'skipped';
        }

        try {
            $result = $this->whatsAppService->sendTemplate(
                $toE164,
                $contentSid,
                [
                    '1' => WhatsAppEventReminderBuckets::leadForBucket($event->name, $bucket),
                    '2' => $event->event_date?->format('j F Y') ?? '',
                    '3' => $event->hasStartTime() ? Carbon::parse($event->event_time)->format('H:i') : 'TBA',
                    '4' => filled($event->venue) ? $event->venue : 'Venue TBA',
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
                    'whatsapp_event_reminders_sent' => WhatsAppEventReminderBuckets::withBucketAppended($already, $bucket),
                ])->saveQuietly();

                return 'sent';
            }

            return 'failed';
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    /**
     * Scheduled email reminder for Accepted guests (7 / 1 / 0 days before event_date) — the email twin of
     * sendWhatsAppEventReminder(); plans/guest-email-reminders.md. Free per message, so it has no WhatsApp-style
     * cost cap, only the shared per-event hourly limit.
     *
     * With $dryRun it applies every rule, sends nothing and writes nothing, and answers 'would_send' for a
     * reminder that a real run would queue. It ignores the feature flag on purpose — the point is to see what
     * switching the flag on would do — and it cannot model the hourly cap building up across a run.
     *
     * @return 'sent'|'would_send'|'disabled'|'skipped'|'rate_limited'|'failed'
     */
    public function sendGuestEventReminderEmail(Event $event, Guest $guest, string $bucket, bool $dryRun = false): string
    {
        if (! $dryRun && ! (bool) config('communications.guest_email_reminders.enabled', false)) {
            return 'disabled';
        }

        if (! $event->ownerCanSendAutomatedReminders()) {
            return 'disabled';
        }

        if ($event->isCancelled() || $event->trashed()) {
            return 'skipped';
        }

        if (! EventReminderBuckets::isAllowed($bucket)) {
            return 'skipped';
        }

        if (! is_string($guest->email) || trim($guest->email) === '') {
            return 'skipped';
        }

        // The guest turned reminder emails off from the link in one of them (plans/guest-email-reminders.md Phase 3).
        if ($guest->hasStoppedEmailReminders()) {
            return 'skipped';
        }

        $guest->loadMissing('rsvp');
        if ($guest->rsvp === null || $guest->rsvp->status !== RsvpStatus::Accepted) {
            return 'skipped';
        }

        // The 09:00 run can land after an early event has started; "today is the big day" then reads as
        // a mistake, and the 1-day reminder already covered it. Only applies to a day-of reminder with a
        // start time — with none there is nothing to be late for.
        if ($bucket === EventReminderBuckets::BUCKET_0 && $event->hasStartTime() && $event->startsAt()?->isPast()) {
            return 'skipped';
        }

        $cap = max(1, (int) config('communications.reminder_hourly_cap_per_event', 500));
        $queuedLastHour = NotificationLog::query()
            ->where('event_id', $event->id)
            ->where('channel', 'email')
            ->where('type', 'guest_event_reminder_email')
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($queuedLastHour >= $cap) {
            return 'rate_limited';
        }

        // The event date is in the key so a moved event is reminded again for its new date.
        $idempotencyKey = sprintf(
            'email-event-reminder:%d:%d:%s:%s',
            $event->id,
            $guest->id,
            $bucket,
            $event->event_date?->format('Y-m-d') ?? 'undated'
        );
        if ($dryRun) {
            $alreadyHandled = NotificationLog::query()
                ->where('idempotency_key', $idempotencyKey)
                ->whereIn('status', [NotificationLog::STATUS_PENDING, NotificationLog::STATUS_SENT])
                ->exists();

            return $alreadyHandled ? 'skipped' : 'would_send';
        }

        $log = $this->startLog($event, $guest, 'email', 'guest_event_reminder_email', $idempotencyKey, [
            'bucket' => $bucket,
        ]);
        if ($log === null) {
            return 'skipped';
        }

        try {
            Notification::route('mail', trim($guest->email))
                ->notify(new GuestEventReminderNotification($event, $guest, $bucket));
            $this->markSent($log);

            return 'sent';
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
                ->first();

            if ($existing !== null) {
                if (in_array($existing->status, [NotificationLog::STATUS_PENDING, NotificationLog::STATUS_SENT], true)) {
                    return null;
                }

                // A failed attempt may be tried again — but idempotency_key is unique, so it has to reuse the
                // failed row rather than insert a second one (which used to throw a unique-constraint error).
                $existing->forceFill([
                    'status' => NotificationLog::STATUS_PENDING,
                    'response' => null,
                    'sent_at' => null,
                    'meta' => $meta,
                ])->save();

                return $existing;
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
