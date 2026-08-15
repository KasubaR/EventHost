<?php

namespace App\Models;

use App\Enums\RsvpStatus;
use Cviebrock\EloquentSluggable\Sluggable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, Sluggable;

    public const EVENT_TYPES = [
        'wedding',
        'birthday',
        'graduation',
        'corporate',
        'baby_shower',
        'funeral',
        'church',
    ];

    /**
     * Display labels for EVENT_TYPES. Note "funeral" reads as "Memorial".
     *
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        'wedding' => 'Wedding',
        'birthday' => 'Birthday',
        'graduation' => 'Graduation',
        'corporate' => 'Corporate Event',
        'baby_shower' => 'Baby Shower',
        'funeral' => 'Memorial',
        'church' => 'Church Event',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'invitation_template_id',
        'name',
        'event_type',
        'description',
        'event_date',
        'event_time',
        'venue',
        'location_name',
        'latitude',
        'longitude',
        'cover_image',
        'is_public',
        'rsvp_deadline',
        'guest_limit',
        'allow_plus_one',
        'show_guest_list',
        'slug',
        'is_published',
        'photo_wall_enabled',
        'photo_wall_requires_approval',
    ];

    /**
     * @return array<string, array<string, mixed|string>>
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name',
            ],
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitationTemplate(): BelongsTo
    {
        return $this->belongsTo(InvitationTemplate::class);
    }

    /**
     * @return HasMany<Guest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    /**
     * @return HasMany<GuestGroup, $this>
     */
    public function guestGroups(): HasMany
    {
        return $this->hasMany(GuestGroup::class)->orderBy('name');
    }

    /**
     * @return HasMany<Rsvp, $this>
     */
    public function rsvps(): HasMany
    {
        return $this->hasMany(Rsvp::class);
    }

    /**
     * @return HasMany<NotificationLog, $this>
     */
    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /**
     * @return HasMany<EventTable, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(EventTable::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<EventPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(EventPhoto::class);
    }

    /**
     * @return HasMany<EventStaffLink, $this>
     */
    public function staffLinks(): HasMany
    {
        return $this->hasMany(EventStaffLink::class)->orderByDesc('created_at');
    }

    /**
     * @return HasOne<Review, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Unpublished drafts a host may keep without paying. Creating more than
     * this is refused so free drafts cannot become unbounded storage.
     */
    public const MAX_OPEN_DRAFTS = 10;

    /**
     * An event whose date has passed is spent — the credit bought that
     * occurrence. Changing what the event *is* from here on costs another
     * credit, otherwise one credit would buy unlimited events. Only
     * published events are chargeable; unpaid past drafts stay free.
     *
     * Guest, table, check-in and photo-wall routes deliberately stay open: they
     * are used during and after the event and cannot be used to recycle it.
     */
    public function isLocked(): bool
    {
        return $this->event_date !== null && $this->event_date->isBefore(today());
    }

    /**
     * Fields that define which event this is. Changing any of them after the
     * event date has passed is a new event in all but name, so it costs a
     * credit — see EventController::update().
     *
     * Everything else (time, venue, description, cover, settings) stays free to
     * change forever: those are corrections, not a different event.
     */
    public const IDENTITY_FIELDS = [
        'name',
        'event_type',
        'event_date',
    ];

    /**
     * Whether the validated payload would change this event's identity.
     *
     * @param  array<string, mixed>  $data
     */
    public function identityChangedBy(array $data): bool
    {
        foreach (self::IDENTITY_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $current = $this->getAttribute($field);
            $incoming = $data[$field];

            // event_date is cast to a Carbon date; compare on the date string so
            // "2026-08-22" and a Carbon instance for the same day match.
            if ($current instanceof Carbon) {
                $current = $current->format('Y-m-d');
                $incoming = $incoming === null ? null : Carbon::parse((string) $incoming)->format('Y-m-d');
            }

            if ((string) $current !== (string) $incoming) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this event has already used a credit for going live. Drafts
     * created under the old "pay on create" rule stored `event_created`; new
     * publishes store `event_published`. Either one means a second publish
     * (or a first publish of a legacy draft) must not charge again.
     */
    public function hasConsumedPublishCredit(): bool
    {
        return $this->creditTransactions()
            ->where('delta', '<', 0)
            ->whereIn('reason', [
                CreditTransaction::REASON_EVENT_CREATED,
                CreditTransaction::REASON_EVENT_PUBLISHED,
            ])
            ->exists();
    }

    public static function openDraftCountFor(int $userId): int
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('is_published', false)
            ->count();
    }

    /**
     * Whether the host may still leave a review for this event.
     *
     * The purchase gate is implicit: publishing an event costs an event credit
     * (see User::canCreateEvent()), so anyone with a reviewable event has
     * necessarily paid for it. Checking `payments` as well would wrongly exclude
     * users an admin granted credits to by hand.
     */
    public function isReviewable(): bool
    {
        return $this->is_published
            && $this->event_date !== null
            && $this->event_date->isBefore(today())
            && $this->review === null;
    }

    /**
     * Author line for a review of this event, e.g. "Wedding · Lusaka".
     */
    public function reviewAuthorContext(): string
    {
        return collect([$this->event_type_label, $this->location_name])
            ->filter(fn (?string $part): bool => is_string($part) && trim($part) !== '')
            ->join(' · ');
    }

    /**
     * Sum of attendee counts for accepted RSVPs only (capacity enforcement).
     */
    public function acceptedAttendeeHeadcount(): int
    {
        return (int) $this->rsvps()
            ->where('status', RsvpStatus::Accepted)
            ->sum('attendee_count');
    }

    /**
     * Maximum attendee count this guest may submit when status is “accepted”.
     */
    public function maxAttendeeSlotsForGuest(Guest $guest): int
    {
        if ($this->allow_plus_one && $guest->plus_one_allowed) {
            return 2;
        }

        return 1;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'invitation_template_id' => 'integer',
            'event_date' => 'date',
            'rsvp_deadline' => 'datetime',
            'is_public' => 'boolean',
            'allow_plus_one' => 'boolean',
            'show_guest_list' => 'boolean',
            'is_published' => 'boolean',
            'photo_wall_enabled' => 'boolean',
            'photo_wall_requires_approval' => 'boolean',
            'invitation_views_count' => 'integer',
            'invitation_customization' => 'array',
            'invitation_customization_previous' => 'array',
            'invitation_customization_previous_captured_at' => 'datetime',
            'invitation_customization_previous_captured_by_user_id' => 'integer',
        ];
    }

    /**
     * Events anyone may see: published by the host and flagged public.
     *
     * This pair is the app's definition of "publicly visible" (see
     * PublicEventController::show()), and is what makes an event eligible for
     * the homepage strip and the discover listing.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->where('is_published', true)->where('is_public', true);
    }

    /**
     * Events happening today or later. Compared by date only — an event earlier
     * today still counts as upcoming, since event_time is not part of the check.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereDate('event_date', '>=', today());
    }

    public function getEventTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->event_type] ?? $this->event_type;
    }

    public function getCoverImageUrlAttribute(): string
    {
        $path = $this->cover_image;

        // Guard: cover_image is a storage-relative path, never a full URL.
        // If it somehow contains a scheme (e.g. "javascript:" or "https://"),
        // something has bypassed request validation — fall back to the default.
        if ($path && ! str_contains($path, '://')) {
            return asset('storage/'.$path);
        }

        return asset('images/default-event.png');
    }

    /**
     * Whether the RSVP window is currently open.
     *
     * The event date is an implicit deadline: an invitation link stays viewable
     * forever as a keepsake, but nobody can pledge to attend something that has
     * already happened. Most hosts never set an explicit rsvp_deadline, so
     * without this a year-old link would still take fresh RSVPs.
     *
     * Pure attribute check — no DB queries. Safe to call on unsaved model instances
     * (e.g. preview events). Do not add relationship lookups here.
     */
    public function isRsvpOpen(): bool
    {
        if ($this->isLocked()) {
            return false;
        }

        if ($this->rsvp_deadline === null) {
            return true;
        }

        return now()->lte($this->rsvp_deadline);
    }

    /**
     * Whether this event's owner is currently entitled to QR check-in / table photo wall.
     * Re-checked live (not cached at event-creation time) so a plan change takes effect immediately.
     */
    public function ownerHasPremiumEventTools(): bool
    {
        return $this->loadMissing('user')->user->canUsePremiumEventTools();
    }

    /**
     * Whether the table photo wall should currently accept uploads and show a public gallery.
     */
    public function photoWallIsLive(): bool
    {
        return $this->is_public
            && $this->photo_wall_enabled
            && $this->ownerHasPremiumEventTools();
    }
}
