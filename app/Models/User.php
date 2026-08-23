<?php

namespace App\Models;

use App\Enums\SubscriptionTier;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const DEFAULT_NOTIFICATION_PREFERENCES = [
        'email_rsvp_updates' => true,
        'email_event_reminders' => true,
        'email_marketing' => false,
        'email_payment_receipts' => true,
        'sms_reminders' => false,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'account_type',
        'email',
        'password',
        'phone',
        'company_name',
        'profile_photo',
        'notification_preferences',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'subscription_tier' => 'none',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if ($user->notification_preferences === null) {
                $user->notification_preferences = self::DEFAULT_NOTIFICATION_PREFERENCES;
            }
        });

        static::updating(function (User $user): void {
            if ($user->isDirty('event_credits')) {
                throw new \RuntimeException('event_credits must be changed via EventCreditService.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'notification_preferences' => 'array',
            'password' => 'hashed',
            'subscription_tier' => SubscriptionTier::class,
        ];
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Events this user has been invited to as staff (Phase 18) — includes
     * pending invites. Use ->whereNotNull('accepted_at') for events they can
     * actually access.
     *
     * @return HasMany<EventStaff, $this>
     */
    public function staffMemberships(): HasMany
    {
        return $this->hasMany(EventStaff::class);
    }

    public function getProfilePhotoUrlAttribute(): string
    {
        return $this->profile_photo
            ? asset('storage/'.$this->profile_photo)
            : asset('images/default-avatar.png');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function subscriptionTier(): SubscriptionTier
    {
        $tier = $this->subscription_tier;

        return $tier instanceof SubscriptionTier
            ? $tier
            : SubscriptionTier::normalize(is_string($tier) ? $tier : null);
    }

    public function subscriptionTierRank(): int
    {
        return $this->subscriptionTier()->rank();
    }

    public function wantsEmailRsvpUpdates(): bool
    {
        return (bool) ($this->notification_preferences['email_rsvp_updates'] ?? true);
    }

    /**
     * Whether the user has a credit available to publish an event or to
     * redefine a past one. Drafts are free and do not consult this.
     */
    public function canCreateEvent(): bool
    {
        return $this->event_credits > 0;
    }

    public function canUseInvitationTemplate(InvitationTemplate $template): bool
    {
        return $this->isActive() && $this->subscriptionTierRank() >= $template->requiredTierRank();
    }

    /**
     * QR check-in + table photo wall — premium event tools, Pro tier and above.
     */
    public function canUsePremiumEventTools(): bool
    {
        return $this->isActive() && $this->subscriptionTierRank() >= SubscriptionTier::Pro->rank();
    }

    /**
     * Curated colour palette picker on the invitation design form — Pro+ only.
     * Everyone else keeps the template's default theme.
     */
    public function canChooseInvitationPalette(): bool
    {
        return $this->isActive() && $this->subscriptionTierRank() >= SubscriptionTier::ProPlus->rank();
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultNotificationPreferences(): array
    {
        return self::DEFAULT_NOTIFICATION_PREFERENCES;
    }
}
