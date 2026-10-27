<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;

class NotificationLog extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Twilio delivery statuses (from the status callback) that mean the message never reached the guest. */
    public const UNDELIVERED_STATUSES = ['failed', 'undelivered'];

    /** Twilio's delivery statuses in the order a message moves through them; a late callback never moves a row back. */
    public const DELIVERY_ORDER = ['queued' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5, 'undelivered' => 5];

    /** Email outcomes (plans/email-gaps.md). `status = sent` on an email row only means it was queued; these say what happened next. */
    public const DELIVERY_ACCEPTED = 'accepted';

    public const DELIVERY_FAILED = 'failed';

    public const DELIVERY_BOUNCED = 'bounced';

    /** Not sent: by the time the worker reached it, it no longer applied (meta.skipped). */
    public const DELIVERY_SUPERSEDED = 'superseded';

    public const EMAIL_UNDELIVERED_STATUSES = [self::DELIVERY_FAILED, self::DELIVERY_BOUNCED];

    /** Email rows that name a guest but went to the host's own address. */
    public const HOST_EMAIL_TYPES = ['host_new_rsvp', 'host_rsvp_awaiting_approval', 'host_event_reminder'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'guest_id',
        'channel',
        'type',
        'status',
        'delivery_status',
        'error_code',
        'idempotency_key',
        'provider_message_id',
        'sent_at',
        'response',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function wasUndelivered(): bool
    {
        return in_array($this->delivery_status, self::UNDELIVERED_STATUSES, true);
    }

    /** The host-facing reason a message did not arrive: Twilio's error code for WhatsApp, ours or the bounce's status for email. */
    public function undeliveredReason(): string
    {
        if ($this->channel === 'email') {
            return self::emailFailureReason($this->error_code);
        }

        return match ((string) $this->error_code) {
            '63003' => "This number isn't on WhatsApp",
            '63024' => 'Message blocked by recipient',
            default => "Couldn't deliver",
        };
    }

    public static function emailFailureReason(?string $code): string
    {
        $code = (string) $code;

        return match (true) {
            $code === 'invalid_address' => 'The address is not valid',
            $code === 'transport' => "The mail server couldn't be reached",
            in_array($code, ['5.1.1', '5.1.2', '5.1.10'], true) => 'The address does not exist',
            str_starts_with($code, '5.2.') => 'The mailbox is not accepting mail',
            str_starts_with($code, '5.7.') => "Refused by the guest's mail server",
            str_starts_with($code, '4.') => 'Delivery delayed',
            default => "Couldn't deliver",
        };
    }

    /** The short code stored for an email that could not be handed to the mail server. Never the exception message: it carries the address. */
    public static function emailFailureCode(\Throwable $e): string
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RfcComplianceException) {
                return 'invalid_address';
            }

            if ($cause instanceof TransportExceptionInterface) {
                return 'transport';
            }
        }

        return 'error';
    }

    /**
     * The reference an outgoing email carries in its X-EventHost-Ref header, so a bounce can be matched to this row.
     * Signed, so a forged bounce report cannot mark someone else's email as bounced.
     */
    public static function mailRef(int $id): string
    {
        return $id.'.'.substr(hash_hmac('sha256', 'mail-ref:'.$id, (string) config('app.key')), 0, 16);
    }

    public static function idFromMailRef(?string $ref): ?int
    {
        if (! is_string($ref) || preg_match('/^(\d{1,19})\.([a-f0-9]{16})$/', trim($ref), $m) !== 1) {
            return null;
        }

        return hash_equals(self::mailRef((int) $m[1]), trim($ref)) ? (int) $m[1] : null;
    }

    public function emailWasUndelivered(): bool
    {
        return in_array($this->delivery_status, self::EMAIL_UNDELIVERED_STATUSES, true);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
