<?php

namespace App\Models;

use App\Enums\RsvpStatus;
use Cviebrock\EloquentSluggable\Sluggable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Pure attribute check — no DB queries. Safe to call on unsaved model instances
     * (e.g. preview events). Do not add relationship lookups here.
     */
    public function isRsvpOpen(): bool
    {
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
