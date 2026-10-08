<?php

namespace App\Models;

use App\Casts\AsRsvpRemindersSent;
use App\Casts\AsWhatsAppEventRemindersSent;
use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Support\GuestPhone;
use App\Support\RsvpReminderBuckets;
use App\Support\WhatsAppEventReminderBuckets;
use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $email_reminders_stopped_at When the guest stopped reminder emails from the link in one; null = still receiving them.
 * @property list<string> $rsvp_reminders_sent Reminder buckets sent; shape enforced by {@see AsRsvpRemindersSent} / {@see RsvpReminderBuckets}.
 * @property list<string> $whatsapp_event_reminders_sent Event-day WhatsApp buckets; {@see AsWhatsAppEventRemindersSent} / {@see WhatsAppEventReminderBuckets}.
 */
class Guest extends Model
{
    /** @use HasFactory<GuestFactory> */
    use HasFactory;

    /**
     * Column length of `name` and `email`: AppServiceProvider sets Schema::defaultStringLength(191), so on MySQL these
     * are VARCHAR(191), not 255. SQLite (the test database) does not enforce length, so only this rule catches it.
     */
    public const NAME_MAX = 191;

    public const EMAIL_MAX = 191;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'guest_group_id',
        'group_link_joined_at',
        'event_table_id',
        'name',
        'email',
        'phone',
        'invitation_token',
        'plus_one_allowed',
        'invitation_sent',
        'invitation_sent_at',
        'rsvp_reminders_sent',
        'whatsapp_event_reminders_sent',
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
     * Seating assignment. Reuses the QR photo-wall's EventTable rather than a
     * second, unrelated "table number" field — see plans/guest-entry-pass.md §0.
     *
     * Deliberately not named table(): Eloquent's base Model class already
     * declares a protected $table property (the DB table name). $this->table
     * accessed from *inside* this class resolves straight to that string
     * property rather than through __get()'s relation lookup — no error, no
     * warning, just silently the wrong value. External access (`$guest->table`
     * from a view) would actually still work, since protected properties trigger
     * __get() outside the class, but relying on that split behaviour is a trap.
     *
     * @return BelongsTo<EventTable, $this>
     */
    public function eventTable(): BelongsTo
    {
        return $this->belongsTo(EventTable::class, 'event_table_id');
    }

    /**
     * @return HasOne<Rsvp, $this>
     */
    public function rsvp(): HasOne
    {
        return $this->hasOne(Rsvp::class);
    }

    /**
     * How this guest's answer changed over time, newest last. See RsvpChange.
     *
     * @return HasMany<RsvpChange, $this>
     */
    public function rsvpChanges(): HasMany
    {
        return $this->hasMany(RsvpChange::class);
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

    /**
     * Who to credit a check-in to, whichever door it came through: a dashboard
     * scan has a real user, a staff-link scan only has the label its link was
     * snapshotted under. Null means nobody has scanned this guest in yet.
     */
    public function checkedInByLabel(): ?string
    {
        return $this->checkedInBy?->name ?? $this->checked_in_via_label;
    }

    public function hasResponded(): bool
    {
        return $this->rsvp()->exists();
    }

    /**
     * True when this guest should see (or be emailed) an entry-pass QR for a
     * given RSVP: accepted, has a personal token to encode, the host's plan
     * includes check-in tools, and — when the event holds RSVPs for host
     * review (require_rsvp_approval) — the host has actually approved this
     * one. RsvpController's web view and RsvpConfirmationNotification's email
     * both funnel through this so the eligibility rule can't drift between
     * the two surfaces; extending it here is also what keeps a Pending or
     * Rejected guest out of the check-in scan flow without a second gate.
     */
    public function hasEntryPassFor(Rsvp $rsvp, Event $event): bool
    {
        return $this->invitation_token !== null
            && $rsvp->status === RsvpStatus::Accepted
            && $rsvp->host_approval_status !== RsvpApprovalStatus::Rejected
            // Pending means "not approved yet", except for an extra seat asked for on top of seats the host already approved.
            && ($rsvp->host_approval_status !== RsvpApprovalStatus::Pending || $rsvp->approvedSeatsOnFile() > 0)
            && $event->ownerHasPremiumEventTools();
    }

    /** The guest opted out of reminder emails from the link in one of them — see stopEmailRemindersUrl(). */
    public function hasStoppedEmailReminders(): bool
    {
        return $this->email_reminders_stopped_at !== null;
    }

    /**
     * Signed **relative** paths for the stop / resume pages. Relative on purpose: the signature then covers
     * only the path, so it survives the bare-domain to www redirect and any proxy host rewriting.
     */
    public function stopEmailRemindersPath(): string
    {
        return URL::signedRoute('guest.email-reminders.stop', ['guest' => $this->getKey()], absolute: false);
    }

    public function resumeEmailRemindersPath(): string
    {
        return URL::signedRoute('guest.email-reminders.resume', ['guest' => $this->getKey()], absolute: false);
    }

    /** Absolute, for an email. */
    public function stopEmailRemindersUrl(): string
    {
        return url($this->stopEmailRemindersPath());
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /**
     * Guests the door would not turn away on their RSVP: everyone except those who declined and those the host rejected.
     * A guest with no answer yet, Maybe, and awaiting approval all stay (they are let in with a warning). Used for
     * the printed QR badges, so nobody gets a badge the scanner would refuse. plans/rsvp-status-changes.md Phase 6.
     *
     * @param  Builder<Guest>  $query
     * @return Builder<Guest>
     */
    public function scopeWantedAtTheDoor(Builder $query): Builder
    {
        return $query->whereDoesntHave('rsvp', fn (Builder $q) => $q
            ->where('status', RsvpStatus::Declined)
            ->orWhere('host_approval_status', RsvpApprovalStatus::Rejected));
    }

    /**
     * Seating label to display alongside the guest's entry pass and on the
     * printed badge sheet — e.g. "Table 5". Null when unassigned; callers must
     * not render a blank row, just omit it.
     */
    public function tableLabel(): ?string
    {
        return $this->eventTable?->label;
    }

    public function personalRsvpUrl(): ?string
    {
        if ($this->invitation_token === null) {
            return null;
        }

        return route('rsvp.token.show', ['token' => $this->invitation_token], absolute: true);
    }

    /**
     * Absolute PNG URL of the full invitation-pass card, for WhatsApp media (Twilio
     * fetches it). Null when the guest has no invitation token — callers must still
     * check hasEntryPassFor. The bare-QR `rsvp.token.entry-pass-png` route still
     * exists for anything already sent, but nothing new points at it.
     */
    public function entryPassPngUrl(): ?string
    {
        if ($this->invitation_token === null) {
            return null;
        }

        return route('rsvp.token.pass-image', ['token' => $this->invitation_token], absolute: true);
    }

    /**
     * Absolute URL of the guest's invitation pass page (the bookmarkable card).
     */
    public function passPageUrl(): ?string
    {
        if ($this->invitation_token === null) {
            return null;
        }

        return route('rsvp.token.pass', ['token' => $this->invitation_token], absolute: true);
    }

    /**
     * Path after the app origin for the WhatsApp RSVP-confirmation Content Template IMAGE header
     * (Twilio/Meta only allow media URL variables after the domain — see docs/twilio.md, and
     * Event::whatsAppInviteHeaderMediaPath() for the invitation card's identical constraint). Points
     * at this guest's own dynamically-rendered pass image (rsvp.token.pass-image), not a stored
     * asset — Twilio only needs the URL to resolve to an image at send time, and that route already
     * degrades to a plain QR PNG rather than failing (see Guest Invitation Pass in CLAUDE.md), so
     * this send is never blocked by a broken renderer. Null when there is no token to build a route
     * from; callers must also check hasEntryPassFor() first, same contract as entryPassPngUrl().
     */
    public function whatsAppPassMediaPath(): ?string
    {
        if ($this->invitation_token === null) {
            return null;
        }

        return ltrim(route('rsvp.token.pass-image', ['token' => $this->invitation_token], absolute: false), '/');
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
     * Compares through GuestPhone::key(), so "0971234567" and "+260 97 123 4567"
     * — the same Zambian number in local vs. country-code form, the single most
     * common way the same guest ends up typed twice — are recognised as one
     * guest, while a foreign number that merely ends in the same nine digits is
     * not. Used by StoreGuestRequest, UpdateGuestRequest (passes $ignoreGuestId)
     * and EventGuestsImport, so every guest-creation path agrees on what counts
     * as a duplicate.
     */
    public static function phoneAlreadyUsed(Event $event, ?string $phone, ?int $ignoreGuestId = null): bool
    {
        return static::matchingPhone($event, $phone, $ignoreGuestId) !== null;
    }

    /**
     * The other guest (if any) on this event sharing $phone's subscriber
     * number — used by StoreOpenRsvpRequest to tell "you're editing your own
     * RSVP again" (same phone AND email) apart from "someone already on the
     * list has this phone" (blocked; see storeOpen()'s comment on why it
     * doesn't just hand back that guest's personal link).
     */
    public static function matchingPhone(Event $event, ?string $phone, ?int $ignoreGuestId = null): ?self
    {
        $key = GuestPhone::key($phone);
        if ($key === null) {
            return null;
        }

        return static::query()
            ->where('event_id', $event->id)
            ->whereNotNull('phone')
            ->when($ignoreGuestId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreGuestId))
            ->get(['id', 'phone', 'email', 'name'])
            ->first(fn (Guest $g): bool => GuestPhone::key($g->phone) === $key);
    }

    /**
     * A typed name as it is stored: trimmed, runs of whitespace collapsed, and in Unicode NFC so "é" typed as one
     * character and as "e" plus an accent are the same name. Null when nothing is left.
     */
    public static function cleanName(?string $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($clean !== '' && class_exists(\Normalizer::class)) {
            $clean = \Normalizer::normalize($clean, \Normalizer::FORM_C) ?: $clean;
        }

        return $clean === '' ? null : $clean;
    }

    /** Other guests on this event with the same name, ignoring case. */
    public static function sameNameCount(Event $event, string $name, ?int $ignoreGuestId = null): int
    {
        return static::query()
            ->where('event_id', $event->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreGuestId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreGuestId))
            ->count();
    }

    /**
     * Host-side QR download name. Carries the id because two guests can share a name, and a name in a script the
     * slugger cannot transliterate slugs to nothing.
     */
    public function qrDownloadName(): string
    {
        $slug = Str::slug((string) $this->name);

        return 'guest-'.$this->id.($slug !== '' ? '-'.$slug : '').'-qr.png';
    }

    /** True when the host has no email and no phone for this guest, so only a copied link reaches them. */
    public function hasNoContactDetails(): bool
    {
        return blank($this->email) && blank($this->phone);
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
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForCheckedInFilter(Builder $query, ?string $filter): Builder
    {
        return match ($filter) {
            'yes' => $query->whereNotNull('checked_in_at'),
            'no' => $query->whereNull('checked_in_at'),
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
            'group_link_joined_at' => 'datetime',
            'rsvp_reminders_sent' => AsRsvpRemindersSent::class,
            'whatsapp_event_reminders_sent' => AsWhatsAppEventRemindersSent::class,
            'email_reminders_stopped_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_in_by' => 'integer',
        ];
    }
}
