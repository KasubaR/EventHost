<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    // Without this, GET /api/v1/me (which returns this Resource directly from the controller)
    // wraps the fields in {"data": {...}}, while the same Resource embedded as a nested value
    // in register/login's JSON (e.g. {"token": ..., "user": new UserResource(...)}) does NOT get
    // that wrapper — JsonResource only auto-wraps when it IS the controller's return value.
    // Flat everywhere keeps `user`/`/me` an identical shape for the client either way.
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'account_type' => $this->account_type,
            'phone' => $this->phone,
            'company_name' => $this->company_name,
            'profile_photo_url' => $this->profile_photo_url,
            'status' => $this->status,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            // ->value, not the enum itself — never make the client re-derive SubscriptionTier's
            // rank ordering; every gate below is the single source of truth for what it unlocks.
            'subscription_tier' => $this->subscriptionTier()->value,
            'event_credits' => $this->event_credits,
            'notification_preferences' => $this->notification_preferences,
            'capabilities' => [
                'can_use_premium_event_tools' => $this->canUsePremiumEventTools(),
                'can_choose_custom_event_slug' => $this->canChooseCustomEventSlug(),
                'can_choose_invitation_palette' => $this->canChooseInvitationPalette(),
                'can_send_automated_reminders' => $this->canSendAutomatedReminders(),
                'can_make_events_public' => $this->canMakeEventsPublic(),
            ],
        ];
    }
}
