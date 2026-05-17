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
        'status',
        'last_login_at',
        'last_login_ip',
        'subscription_tier',
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

    public function canUseInvitationTemplate(InvitationTemplate $template): bool
    {
        return $this->isActive() && $this->subscriptionTierRank() >= $template->requiredTierRank();
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultNotificationPreferences(): array
    {
        return self::DEFAULT_NOTIFICATION_PREFERENCES;
    }
}
