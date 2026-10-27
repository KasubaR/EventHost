<?php

namespace App\Notifications\Concerns;

use App\Models\EmailSuppression;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Every queued email to or about a guest is checked again when the worker sends it, not only when it was queued
 * (plans/email-gaps.md). By then the guest may be deleted, the event deleted, purged or cancelled, the address changed,
 * or the moment passed; each notification adds its own reasons in `whyNoLongerRelevant()`.
 *
 * `$deleteWhenMissingModels` does nothing here: Laravel reads it from the queued job class (SendQueuedNotifications),
 * never from the notification. Instead a missing Guest, Rsvp or Event is restored as an empty, unsaved stand-in and
 * `shouldSend()` refuses it, so the job ends quietly with no mail and no failed_jobs row.
 *
 * With a log row attached (`withLog()`, which CommunicationService always does), the row records what happened next:
 * superseded (skipped here), accepted (RecordEmailAccepted), failed (`failed()`) or bounced (mail:process-bounces).
 */
trait GuardsGuestMail
{
    /** The notification_logs row this email belongs to; null when it is sent outside CommunicationService. */
    public ?int $notificationLogId = null;

    /** When it was queued (unix time). A guest email still unsent a day later is dropped rather than sent late. */
    public ?int $queuedAt = null;

    public int $tries = 4;

    public const EXPIRES_AFTER_SECONDS = 86400;

    public function withLog(?NotificationLog $log): static
    {
        $this->notificationLogId = $log?->id;
        $this->queuedAt = now()->getTimestamp();

        return $this;
    }

    /**
     * Wait 1, 5 and 15 minutes between attempts, so a short mail-server outage is ridden out instead of failing
     * all four attempts within seconds.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $reason = $this->reasonNotToSend($notifiable, $channel);

        if ($reason !== null) {
            $this->recordSkipped($reason);

            return false;
        }

        // An address the mailer would refuse fails now, rather than on each of four attempts over 20 minutes.
        $to = $notifiable instanceof AnonymousNotifiable ? ($notifiable->routes['mail'] ?? null) : null;
        if ($channel === 'mail' && is_string($to)) {
            try {
                new Address(trim($to));
            } catch (RfcComplianceException $e) {
                $this->failed($e);

                return false;
            }
        }

        return true;
    }

    /** Why this email should not go out now, or null to send it. */
    public function reasonNotToSend(object $notifiable, string $channel): ?string
    {
        if (property_exists($this, 'guest') && ! $this->guest->exists) {
            return 'guest_deleted';
        }

        if (property_exists($this, 'rsvp') && ! $this->rsvp->exists) {
            return 'rsvp_deleted';
        }

        if (property_exists($this, 'event') && (! $this->event->exists || $this->event->trashed())) {
            return 'event_deleted';
        }

        if ($this->queuedAt !== null && now()->getTimestamp() - $this->queuedAt > self::EXPIRES_AFTER_SECONDS) {
            return 'expired';
        }

        if ($channel === 'mail' && $notifiable instanceof AnonymousNotifiable) {
            $to = $notifiable->routes['mail'] ?? null;

            if (is_string($to) && property_exists($this, 'guest')
                && mb_strtolower(trim($to)) !== mb_strtolower(trim((string) $this->guest->email))) {
                return 'address_changed';
            }

            // It hard-bounced after this was queued.
            if (is_string($to) && EmailSuppression::isSuppressed($to)) {
                return 'suppressed';
            }
        }

        return method_exists($this, 'whyNoLongerRelevant') ? $this->whyNoLongerRelevant() : null;
    }

    /** Adds the X-EventHost-Ref header a bounce report carries back, so the bounce can be matched to the log row. */
    protected function tagMail(MailMessage $mail): MailMessage
    {
        if ($this->notificationLogId === null) {
            return $mail;
        }

        $ref = NotificationLog::mailRef($this->notificationLogId);

        return $mail->withSymfonyMessage(function (Email $message) use ($ref): void {
            $message->getHeaders()->addTextHeader('X-EventHost-Ref', $ref);
        });
    }

    /** Every attempt failed (or the address was refused outright). The reason stored never includes the address. */
    public function failed(\Throwable $e): void
    {
        $log = $this->notificationLogId !== null ? NotificationLog::query()->find($this->notificationLogId) : null;

        $log?->forceFill([
            'status' => NotificationLog::STATUS_FAILED,
            'delivery_status' => NotificationLog::DELIVERY_FAILED,
            'error_code' => NotificationLog::emailFailureCode($e),
            'response' => class_basename($e),
        ])->save();
    }

    private function recordSkipped(string $reason): void
    {
        $log = $this->notificationLogId !== null ? NotificationLog::query()->find($this->notificationLogId) : null;

        if ($log === null || $log->delivery_status !== null) {
            return;
        }

        $log->forceFill([
            'delivery_status' => NotificationLog::DELIVERY_SUPERSEDED,
            'meta' => array_merge(is_array($log->meta) ? $log->meta : [], ['skipped' => $reason]),
        ])->save();
    }

    protected function getRestoredPropertyValue($value)
    {
        try {
            return parent::getRestoredPropertyValue($value);
        } catch (ModelNotFoundException $e) {
            $class = $e->getModel();

            if (is_string($class) && (is_a($class, Guest::class, true) || is_a($class, Rsvp::class, true) || is_a($class, Event::class, true))) {
                return new $class;
            }

            throw $e;
        }
    }
}
