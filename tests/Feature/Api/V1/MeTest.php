<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_pro_plus_user_has_every_capability_flag_true(): void
    {
        $user = User::factory()->proPlus()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('subscription_tier', 'pro_plus')
            ->assertJsonPath('capabilities.can_use_premium_event_tools', true)
            ->assertJsonPath('capabilities.can_choose_custom_event_slug', true)
            ->assertJsonPath('capabilities.can_choose_invitation_palette', true)
            ->assertJsonPath('capabilities.can_send_automated_reminders', true)
            ->assertJsonPath('capabilities.can_make_events_public', true);
    }

    public function test_a_base_tier_user_has_the_higher_tier_flags_false(): void
    {
        $user = User::factory()->create(); // factory default subscription_tier: base

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('capabilities.can_use_premium_event_tools', false)
            ->assertJsonPath('capabilities.can_choose_custom_event_slug', false)
            ->assertJsonPath('capabilities.can_choose_invitation_palette', false)
            ->assertJsonPath('capabilities.can_send_automated_reminders', false)
            ->assertJsonPath('capabilities.can_make_events_public', true); // base is the lowest qualifying tier
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_reflects_email_verification_immediately_after_the_web_verify_link_is_used(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertJsonPath('email_verified_at', null);

        // Simulates what the existing web verification.verify route does when the user taps the
        // emailed link in a browser/Custom Tab — the Android app has no JSON endpoint for this
        // step by design, it just pulls /me afterward.
        $user->markEmailAsVerified();

        // Illuminate\Auth\RequestGuard caches its resolved user for the guard instance's
        // lifetime, and Laravel's test container reuses that instance across sequential calls
        // within one test method — without forgetting it, this second call would see the first
        // call's stale (pre-verification) user instead of re-resolving from the request/DB.
        auth()->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('email_verified_at', fn ($value) => $value !== null);
    }
}
