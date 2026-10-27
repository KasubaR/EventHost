<?php

namespace App\Services;

use App\Enums\EventAudience;
use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\ContributionPayment;
use App\Models\EmailSuppression;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\Guest;
use App\Models\GuestRemoval;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\ContributionReceiptNotification;
use App\Notifications\EventUpdatedNotification;
use App\Notifications\GuestEventReminderNotification;
use App\Notifications\GuestRemovedNotification;
use App\Notifications\HostEventReminderNotification;
use App\Notifications\HostPurgeWarningNotification;
use App\Notifications\NewContributionReceivedNotification;
use App\Notifications\NewRsvpReceivedNotification;
use App\Notifications\PlusOneRemovedNotification;
use App\Notifications\RsvpAwaitingApprovalNotification;
use App\Notifications\RsvpConfirmationNotification;
use App\Notifications\RsvpExtraSeatPendingNotification;
use App\Notifications\RsvpRejectedNotification;
use App\Notifications\RsvpReminderNotification;
use App\Support\EmailSkips;
use App\Support\EventReminderBuckets;
use App\Support\ShortText;
use App\Support\WhatsAppEventReminderBuckets;
use App\Support\ZambianPhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CommunicationService
{
    public const WHATSAPP_INVITATION_TYPE = 'guest_invitation_whatsapp';

    /** A second automated invitation inside this window needs the host's "Send again". */
    public const WHATSAPP_INVITATION_RESEND_HOURS = 24;

    /** A send still pending this recently is a double click, and is refused even with "Send again". */
    public const WHATSAPP_INVITATION_DOUBLE_SUBMIT_MINUTES = 2;

    public function __construct(
        private readonly SmsService $smsService,
        private readonly WhatsAppService $whatsAppService,
    ) {}

    /**
     * A guest address worth sending to: present, and not one that hard-bounced (EmailSuppression, plans/email-gaps.md).
     * Bulk actions call it too, to count the guests they skip.
     */
    public static function canEmail(?string $email): bool
    {
        return is_string($email) && trim($email) !== '' && ! EmailSuppression::isSuppressed($email);
    }

    public function sendRsvpReminder(Event $event, Guest $guest, int $daysUntilDeadline, ?string $idempotencyKey = null): void
    {
        if (! $event->ownerCanSendAutomatedReminders()) {
            return;
        }

        if (! self::canEmail($guest->email)) {
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
                ->notify((new RsvpReminderNotification($event, $guest, $daysUntilDeadline))->withLog($log));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    public function sendRsvpConfirmation(Event $event, Guest $guest, Rsvp $rsvp): void
    {
        if (! self::canEmail($guest->email)) {
            return;
        }

        $log = $this->startLog($event, $guest, 'email', 'rsvp_confirmation', null, null);
        if ($log === null) {
            return;
        }

        try {
            Notification::route('mail', $guest->email)
                ->notify((new RsvpConfirmationNotification($event, $guest, $rsvp))->withLog($log));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    /**
     * Guest-facing: an extra seat was added on top of seats the host already approved. The pass stays valid for the
     * approved seats; this says the extra one is waiting. Skipped silently when the guest gave no email.
     */
    public function sendRsvpExtraSeatPending(Event $event, Guest $guest, Rsvp $rsvp): void
    {
        if (! self::canEmail($guest->email)) {
            return;
        }

        $log = $this->startLog($event, $guest, 'email', 'rsvp_extra_seat_pending', null, null);
        if ($log === null) {
            return;
        }

        try {
            Notification::route('mail', $guest->email)
                ->notify((new RsvpExtraSeatPendingNotification($event, $guest, $rsvp))->withLog($log));
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
            $host->notify((new NewRsvpReceivedNotification($event, $guest, $rsvp, $rsvp->previousAnswer))->withLog($log));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    /**
     * Host-facing twin of notifyHostNewRsvp() for an Accepted RSVP the event is holding for
     * review (require_rsvp_approval) — sent instead of, never alongside, that notification.
     */
    public function notifyHostRsvpAwaitingApproval(User $host, Event $event, Guest $guest, Rsvp $rsvp): void
    {
        $wantsEmail = (bool) ($host->notification_preferences['email_rsvp_updates'] ?? true);
        $wantsPush = (bool) ($host->notification_preferences['push_rsvp_updates'] ?? true);

        if (! $wantsEmail && ! $wantsPush) {
            return;
        }

        $log = $this->startLog($event, $guest, 'email', 'host_rsvp_awaiting_approval', null, ['host_user_id' => $host->id]);
        if ($log === null) {
            return;
        }

        try {
            $host->notify((new RsvpAwaitingApprovalNotification($event, $guest, $rsvp))->withLog($log));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    /**
     * Guest-facing: the host declined an Accepted RSVP under require_rsvp_approval. Called by
     * RsvpApprovalService::reject(), never as part of dispatchRsvpNotifications().
     */
    public function sendRsvpRejection(Event $event, Guest $guest, Rsvp $rsvp, string $note, bool $extraSeatOnly = false): void
    {
        if (! self::canEmail($guest->email)) {
            return;
        }

        $log = $this->startLog($event, $guest, 'email', 'rsvp_rejected', null, null);
        if ($log === null) {
            return;
        }

        try {
            Notification::route('mail', $guest->email)
                ->notify((new RsvpRejectedNotification($event, $guest, $rsvp, $note, $extraSeatOnly))->withLog($log));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    /**
     * Guest-facing: the host removed a confirmed plus-one. Skipped silently when the guest gave no email.
     */
    public function sendPlusOneRemoved(Event $event, Guest $guest): void
    {
        if (! self::canEmail($guest->email)) {
            return;
        }

        $log = $this->startLog($event, $guest, 'email', 'plus_one_removed', null, null);
        if ($log === null) {
            return;
        }

        try {
            Notification::route('mail', $guest->email)
                ->notify((new PlusOneRemovedNotification($event, $guest))->withLog($log));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    /**
     * Shared by web RSVP, API RSVP, and WhatsApp inbound quick-reply — keep side effects identical,
     * with one deliberate exception: $viaWhatsAppInbound. A quick-reply tap never opens a web
     * request that could also fire the business-initiated WhatsApp confirmation below — it already
     * gets a *better* one, a free session reply with the pass attached, sent directly by
     * WhatsAppInboundRsvpService right after this call returns. Without the flag, that guest would
     * get two WhatsApp messages for one RSVP.
     */
    public function dispatchRsvpNotifications(Event $event, Guest $guest, Rsvp $rsvp, bool $viaWhatsAppInbound = false): void
    {
        // A resubmit of the answer already on file (double tap, refresh, retry) tells nobody anything new.
        if (! $rsvp->submissionChanged) {
            return;
        }

        try {
            $rsvp->loadMissing('guest');

            if ($rsvp->host_approval_status === RsvpApprovalStatus::Pending) {
                // Held for review: no guest confirmation, no pass, no WhatsApp send until the
                // host approves or rejects it — see RsvpApprovalService and plans/rsvp-host-approval.md.
                $event->loadMissing('user');
                $host = $event->user;

                if ($host !== null) {
                    $this->notifyHostRsvpAwaitingApproval($host, $event, $guest, $rsvp);
                }

                // A guest who already holds approved seats keeps their pass; tell them the extra seat is with the host.
                if ($rsvp->approvedSeatsOnFile() > 0) {
                    $this->sendRsvpExtraSeatPending($event, $guest, $rsvp);
                }

                return;
            }

            if (is_string($guest->email) && $guest->email !== '') {
                $this->sendRsvpConfirmation($event, $guest, $rsvp);
            }

            if (! $viaWhatsAppInbound) {
                $this->sendWhatsAppRsvpConfirmation($event, $guest, $rsvp);
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
     * Called by RsvpApprovalService::approve(), never from dispatchRsvpNotifications() itself —
     * this is the same confirmation + WhatsApp-pass send an approval-not-required Accepted RSVP
     * already gets, just fired once the host decides instead of at submit time. Reuses
     * sendRsvpConfirmation()/sendWhatsAppRsvpConfirmation() rather than duplicating them, so the
     * guest's confirmation is byte-for-byte the same either way.
     */
    public function dispatchApprovedRsvpNotifications(Event $event, Guest $guest, Rsvp $rsvp): void
    {
        try {
            $rsvp->loadMissing('guest');

            if (is_string($guest->email) && $guest->email !== '') {
                $this->sendRsvpConfirmation($event, $guest, $rsvp);
            }

            $this->sendWhatsAppRsvpConfirmation($event, $guest, $rsvp);
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

    public function sendEventUpdate(Event $event, Guest $guest, string $updateMessage, string $logType = 'event_update'): void
    {
        if (! self::canEmail($guest->email)) {
            return;
        }

        $log = $this->startLog($event, $guest, 'email', $logType, null, ['message_hash' => EmailSkips::messageHash($updateMessage)]);
        if ($log === null) {
            return;
        }

        try {
            Notification::route('mail', $guest->email)
                ->notify((new EventUpdatedNotification($event, $guest, $updateMessage))->withLog($log));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }
    }

    /**
     * The one "you were removed" email, sent from the host's "Tell them" button on a removed guest. The guest row is gone,
     * so this works from the tombstone. Idempotent per removal: a second call is a no-op.
     */
    public function sendGuestRemoved(Event $event, GuestRemoval $removal): bool
    {
        if (! $removal->canBeTold() || ! self::canEmail($removal->email)) {
            return false;
        }

        $log = $this->startLog($event, null, 'email', 'guest_removed_notice', 'guest-removed:'.$removal->id, ['removal_id' => $removal->id]);
        if ($log === null) {
            return false;
        }

        try {
            Notification::route('mail', $removal->email)
                ->notify((new GuestRemovedNotification($event, $removal->name))->withLog($log));
            $this->markSent($log);
        } catch (\Throwable $e) {
            $this->markFailed($log, $e);
            throw $e;
        }

        $removal->forceFill(['notified_at' => now()])->save();

        return true;
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
     * Each send is billed, so a second one inside WHATSAPP_INVITATION_RESEND_HOURS answers 'already_sent' unless $resend
     * (the host pressed "Send again"). A send still in flight from the last WHATSAPP_INVITATION_DOUBLE_SUBMIT_MINUTES always
     * blocks, resend or not: that is a double click. A send Twilio later reported undelivered does not count.
     *
     * @return 'sent'|'already_sent'|'disabled'|'unpublished'|'closed'|'invalid_phone'|'rate_limited'|'failed'
     */
    public function sendWhatsAppInvitation(Event $event, Guest $guest, bool $resend = false): string
    {
        if (! (bool) config('communications.whatsapp.enabled', false)) {
            return 'disabled';
        }

        // The personal link would only show "Invitation unavailable" until the event is published.
        if (! $event->is_published) {
            return 'unpublished';
        }

        // The card's Yes / No / Maybe buttons and the link would all land on a closed form
        // (plans/rsvp-deadline-fixes.md G10). Nothing is sent, so nothing is billed.
        if (! $event->isRsvpOpen()) {
            return 'closed';
        }

        $toE164 = ZambianPhone::toE164($guest->phone);
        if ($toE164 === null || $guest->invitation_token === null) {
            return 'invalid_phone';
        }

        // The checks and the pending row share the event's row lock, so two clicks (or two tabs) cannot both pass the
        // "already sent" check before either has written its row. The Twilio call itself runs after the commit.
        $log = DB::transaction(function () use ($event, $guest, $resend): NotificationLog|string {
            Event::query()->whereKey($event->id)->lockForUpdate()->first();

            $previous = $this->lastWhatsAppInvitation($event, $guest);
            if ($previous !== null) {
                $inFlight = $previous->status === NotificationLog::STATUS_PENDING
                    && $previous->created_at->gt(now()->subMinutes(self::WHATSAPP_INVITATION_DOUBLE_SUBMIT_MINUTES));

                if ($inFlight || ! $resend) {
                    return 'already_sent';
                }
            }

            // Event-scoped guard on top of the per-user 'guest-whatsapp-send' route throttle: each send costs real money.
            // Only invitations count, so a burst of invitations never holds back the confirmation a replying guest is owed.
            if ($this->whatsAppSentLastHour($event, self::WHATSAPP_INVITATION_TYPE, includePending: true) >= $this->whatsAppHourlyCap()) {
                return 'rate_limited';
            }

            return NotificationLog::query()->create([
                'event_id' => $event->id,
                'guest_id' => $guest->id,
                'channel' => 'whatsapp',
                'type' => self::WHATSAPP_INVITATION_TYPE,
                'status' => NotificationLog::STATUS_PENDING,
                'meta' => $resend ? ['resend' => true] : null,
            ]);
        });

        if (is_string($log)) {
            return $log;
        }

        try {
            // Numbered slots match the Meta/Twilio Quick Reply Content Template
            // (plans/whatsapp-invitations.md / docs/twilio.md): body {{1}}..{{5}} details,
            // {{6}} = full personal RSVP URL for plus-ones; Yes/No/Maybe are template buttons.
            $result = $this->whatsAppService->sendTemplate(
                $toE164,
                (string) config('services.twilio.invitation_content_sid'),
                [
                    '1' => filled($guest->name) ? ShortText::whatsapp($guest->name) : 'Guest',
                    '2' => ShortText::whatsapp($event->name),
                    '3' => $event->event_date?->format('j F Y') ?? '',
                    '4' => $event->hasStartTime() ? Carbon::parse($event->event_time)->format('H:i') : 'TBA',
                    '5' => filled($event->venue) ? ShortText::whatsapp($event->venue) : 'Venue TBA',
                    // Body footnote for plus-ones / full form — Quick Reply template has no URL button.
                    '6' => (string) $guest->personalRsvpUrl(),
                    // IMAGE header path after the production host (template: https://HOST/{{7}}).
                    '7' => $event->whatsAppInviteHeaderMediaPath(),
                ]
            );
            $status = $result['status'] === 'sent' ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED;
            // One instant for both rows: the status callback un-marks the guest only when this send is what marked them.
            $sentAt = now()->startOfSecond();
            $log->forceFill([
                'status' => $status,
                'provider_message_id' => $result['provider_message_id'],
                'response' => $result['response'],
                'sent_at' => $status === NotificationLog::STATUS_SENT ? $sentAt : null,
            ])->save();

            if ($status === NotificationLog::STATUS_SENT) {
                $guest->forceFill([
                    'invitation_sent' => true,
                    'invitation_sent_at' => $sentAt,
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
     * WhatsApp entry-pass confirmation for the web/API RSVP path — the business-initiated
     * counterpart to the inbound-quick-reply session confirmation in WhatsAppInboundRsvpService.
     * A web-form submit never talks to Twilio, so there is no open session to reply into; this
     * sends its own approved whatsapp/card template instead, same as sendWhatsAppInvitation.
     *
     * Eligibility mirrors Guest::hasEntryPassFor() exactly — Accepted, has invitation_token, host
     * on a plan with ownerHasPremiumEventTools(). Declined/Maybe RSVPs and non-premium hosts get
     * email only (unchanged); there is no text-only fallback template for those, unlike the inbound
     * flow's sendText branch — see plans/whatsapp-invitations.md for why that's a deliberate choice,
     * not an oversight.
     *
     * @return 'sent'|'disabled'|'skipped'|'invalid_phone'|'rate_limited'|'failed'
     */
    public function sendWhatsAppRsvpConfirmation(Event $event, Guest $guest, Rsvp $rsvp): string
    {
        if (! (bool) config('communications.whatsapp.enabled', false)) {
            return 'disabled';
        }

        if (! $guest->hasEntryPassFor($rsvp, $event)) {
            return 'skipped';
        }

        $toE164 = ZambianPhone::toE164($guest->phone);
        if ($toE164 === null) {
            return 'invalid_phone';
        }

        $mediaPath = $guest->whatsAppPassMediaPath();
        if ($mediaPath === null) {
            // hasEntryPassFor() already checked invitation_token !== null, so this is unreachable in
            // practice — kept because whatsAppPassMediaPath()'s own contract returns nullable.
            return 'skipped';
        }

        $contentSid = (string) config('services.twilio.rsvp_confirmation_content_sid', '');
        if ($contentSid === '') {
            return 'disabled';
        }

        if ($this->whatsAppSentLastHour($event, 'guest_rsvp_confirmation_whatsapp') >= $this->whatsAppHourlyCap()) {
            return 'rate_limited';
        }

        // No idempotency key — an edited RSVP notifies again like the email side. An unchanged
        // resubmit never reaches here: dispatchRsvpNotifications() returns early (Rsvp::$submissionChanged).
        $log = $this->startLog($event, $guest, 'whatsapp', 'guest_rsvp_confirmation_whatsapp', null, null);
        if ($log === null) {
            return 'failed';
        }

        try {
            // Numbered slots match the approved whatsapp/card template (plans/whatsapp-invitations.md
            // "Web/API RSVP confirmation"): body {{1}}..{{6}}, {{7}} = media path after production host.
            $result = $this->whatsAppService->sendTemplate(
                $toE164,
                $contentSid,
                [
                    '1' => filled($guest->name) ? ShortText::whatsapp($guest->name) : 'Guest',
                    '2' => ShortText::whatsapp($event->name),
                    '3' => $event->event_date?->format('j F Y') ?? '',
                    '4' => $event->hasStartTime() ? Carbon::parse($event->event_time)->format('H:i') : 'TBA',
                    '5' => filled($event->venue) ? ShortText::whatsapp($event->venue) : 'Venue TBA',
                    '6' => (string) $guest->passPageUrl(),
                    '7' => $mediaPath,
                ]
            );
            $status = $result['status'] === 'sent' ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED;
            $log->forceFill([
                'status' => $status,
                'provider_message_id' => $result['provider_message_id'],
                'response' => $result['response'],
                'sent_at' => $status === NotificationLog::STATUS_SENT ? now() : null,
            ])->save();

            return $status === NotificationLog::STATUS_SENT ? 'sent' : 'failed';
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

        if ($this->whatsAppSentLastHour($event, 'guest_event_reminder_whatsapp') >= $this->whatsAppHourlyCap()) {
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
                    '1' => WhatsAppEventReminderBuckets::leadForBucket(ShortText::whatsapp($event->name), $bucket),
                    '2' => $event->event_date?->format('j F Y') ?? '',
                    '3' => $event->hasStartTime() ? Carbon::parse($event->event_time)->format('H:i') : 'TBA',
                    '4' => filled($event->venue) ? ShortText::whatsapp($event->venue) : 'Venue TBA',
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

        if (! self::canEmail($guest->email)) {
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
                ->notify((new GuestEventReminderNotification($event, $guest, $bucket))->withLog($log));
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
    /**
     * The latest automated WhatsApp invitation to this guest that still counts as sent: sent or in flight within
     * WHATSAPP_INVITATION_RESEND_HOURS, and not reported undelivered by Twilio afterwards.
     */
    public function lastWhatsAppInvitation(Event $event, Guest $guest): ?NotificationLog
    {
        return NotificationLog::query()
            ->where('event_id', $event->id)
            ->where('guest_id', $guest->id)
            ->where('type', self::WHATSAPP_INVITATION_TYPE)
            ->whereIn('status', [NotificationLog::STATUS_PENDING, NotificationLog::STATUS_SENT])
            ->where(function ($query): void {
                $query->whereNull('delivery_status')->orWhereNotIn('delivery_status', NotificationLog::UNDELIVERED_STATUSES);
            })
            ->where('created_at', '>=', now()->subHours(self::WHATSAPP_INVITATION_RESEND_HOURS))
            ->latest('id')
            ->first();
    }

    private function whatsAppHourlyCap(): int
    {
        return max(1, (int) config('communications.whatsapp.hourly_cap_per_event', 100));
    }

    /** One type's WhatsApp sends for this event in the last hour; each kind of message has its own allowance. */
    private function whatsAppSentLastHour(Event $event, string $type, bool $includePending = false): int
    {
        return NotificationLog::query()
            ->where('event_id', $event->id)
            ->where('channel', 'whatsapp')
            ->where('type', $type)
            ->whereIn('status', $includePending
                ? [NotificationLog::STATUS_SENT, NotificationLog::STATUS_PENDING]
                : [NotificationLog::STATUS_SENT])
            ->where('created_at', '>=', now()->subHour())
            ->count();
    }

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
                    'delivery_status' => null,
                    'error_code' => null,
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
        // A mail exception's message can carry the guest's address; store what kind of failure it was instead.
        if ($log->channel === 'email') {
            $log->forceFill([
                'status' => NotificationLog::STATUS_FAILED,
                'delivery_status' => NotificationLog::DELIVERY_FAILED,
                'error_code' => NotificationLog::emailFailureCode($e),
                'response' => class_basename($e),
            ])->save();

            return;
        }

        $log->forceFill([
            'status' => NotificationLog::STATUS_FAILED,
            'response' => substr($e->getMessage(), 0, 65535),
        ])->save();
    }
}
