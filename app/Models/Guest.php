<?php

namespace App\Models;

use App\Casts\AsRsvpRemindersSent;
use App\Support\RsvpReminderBuckets;
use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property list<string> $rsvp_reminders_sent Reminder buckets sent; shape enforced by {@see AsRsvpRemindersSent} / {@see RsvpReminderBuckets}.
 */
class Guest extends Model
{
    /** @use HasFactory<GuestFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'guest_group_id',
        'name',
        'email',
        'phone',
        'invitation_token',
        'plus_one_allowed',
        'invitation_sent',
        'invitation_sent_at',
        'rsvp_reminders_sent',
    ];

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<GuestGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(GuestGroup::class, 'guest_group_id');
    }

    /**
     * @return HasOne<Rsvp, $this>
     */
    public function rsvp(): HasOne
    {
        return $this->hasOne(Rsvp::class);
    }

    /**
     * @return HasMany<NotificationLog, $this>
     */
    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function hasResponded(): bool
    {
        return $this->rsvp()->exists();
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    public function personalRsvpUrl(): ?string
    {
        if ($this->invitation_token === null) {
            return null;
        }

        return route('rsvp.token.show', ['token' => $this->invitation_token], absolute: true);
    }

    /**
     * URL encoded into the guest's printable/emailed QR code. It targets the staff-only,
     * auth-protected check-in confirm endpoint — not the public RSVP link — so a guest
     * scanning their own invitation cannot self-check-in before arriving; only a logged-in
     * host/staff member's scanner page can act on it. See CheckInController.
     */
    public function checkInQrUrl(): ?string
    {
        if ($this->invitation_token === null) {
            return null;
        }

        return route('events.checkin.confirm-token', [
            'event' => $this->event_id,
            'token' => $this->invitation_token,
        ], absolute: true);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = is_string($term) ? trim($term) : '';
        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForGuestGroupFilter(Builder $query, ?string $groupId): Builder
    {
        if ($groupId === null || $groupId === '' || $groupId === 'all') {
            return $query;
        }

        if ($groupId === 'none') {
            return $query->whereNull('guest_group_id');
        }

        return $query->where('guest_group_id', $groupId);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForInvitationSentFilter(Builder $query, ?string $filter): Builder
    {
        return match ($filter) {
            'yes' => $query->where('invitation_sent', true),
            'no' => $query->where('invitation_sent', false),
            default => $query,
        };
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForPlusOneFilter(Builder $query, ?string $filter): Builder
    {
        return match ($filter) {
            'yes' => $query->where('plus_one_allowed', true),
            'no' => $query->where('plus_one_allowed', false),
            default => $query,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plus_one_allowed' => 'boolean',
            'invitation_sent' => 'boolean',
            'invitation_sent_at' => 'datetime',
            'rsvp_reminders_sent' => AsRsvpRemindersSent::class,
            'checked_in_at' => 'datetime',
            'checked_in_by' => 'integer',
        ];
    }
}
