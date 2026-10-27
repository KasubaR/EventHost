<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address that hard-bounced, so guest email to it stops for every event (plans/email-gaps.md Phase 3). Written only by
 * EmailBounceProcessor; removed by the host's "Try this address again" or a year after the last bounce
 * (mail:process-bounces). Privacy §3 and §7 describe it.
 */
class EmailSuppression extends Model
{
    public const REASON_HARD_BOUNCE = 'hard_bounce';

    /** Kept this long after the last bounce, then dropped: an address can come back into use. */
    public const KEEP_DAYS = 365;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'reason',
        'status_code',
        'diagnostic',
        'notification_log_id',
        'bounce_count',
        'first_bounced_at',
        'last_bounced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_bounced_at' => 'datetime',
            'last_bounced_at' => 'datetime',
            'bounce_count' => 'integer',
        ];
    }

    public static function normalize(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    public static function forEmail(?string $email): ?self
    {
        $email = self::normalize($email);

        return $email === null ? null : self::query()->where('email', $email)->first();
    }

    public static function isSuppressed(?string $email): bool
    {
        $email = self::normalize($email);

        return $email !== null && self::query()->where('email', $email)->exists();
    }

    public function reasonLabel(): string
    {
        return NotificationLog::emailFailureReason($this->status_code);
    }

    /**
     * @return BelongsTo<NotificationLog, $this>
     */
    public function notificationLog(): BelongsTo
    {
        return $this->belongsTo(NotificationLog::class);
    }
}
