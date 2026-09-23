<?php

namespace Tests\Feature;

use App\Enums\SubscriptionTier;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_is_idempotent(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->completed()->create([
            'plan_key' => 'pro',
            'credits_granted' => 1,
            'notified_at' => null,
        ]);

        $service = app(PaymentCompletionService::class);
        $service->complete($payment);
        $service->complete($payment->fresh());

        $user->refresh();
        $this->assertSame(1, $user->event_credits);
        $this->assertSame(SubscriptionTier::Pro, $user->subscriptionTier());
    }

    public function test_completion_upgrades_tier_but_never_downgrades(): void
    {
        $user = User::factory()->withoutCredits()->pro()->create();
        $payment = Payment::factory()->for($user)->completed()->create([
            'plan_key' => 'base',
            'credits_granted' => 1,
            'notified_at' => null,
        ]);

        app(PaymentCompletionService::class)->complete($payment);

        $user->refresh();
        $this->assertSame(1, $user->event_credits);
        $this->assertSame(SubscriptionTier::Pro, $user->subscriptionTier());
    }

    public function test_completion_does_not_grant_again_when_credits_are_already_fulfilled(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->completed()->create([
            'plan_key' => 'base',
            'credits_granted' => 1,
            'credits_fulfilled_at' => now(),
            'notified_at' => null,
        ]);

        app(PaymentCompletionService::class)->complete($payment);
        app(PaymentCompletionService::class)->complete($payment->fresh());

        $this->assertSame(0, $user->fresh()->event_credits);
        $this->assertNotNull($payment->fresh()->notified_at);
    }

    public function test_completion_of_top_up_raises_tier_without_granting_credits(): void
    {
        $user = User::factory()->create([
            'subscription_tier' => SubscriptionTier::Base,
            'event_credits' => 1,
        ]);
        $payment = Payment::factory()->for($user)->completed()->create([
            'plan_key' => 'pro',
            'credits_granted' => 0,
            'amount' => 300.00,
            'notified_at' => null,
            'metadata' => [
                'upgrade' => true,
                'previous_tier' => 'base',
            ],
        ]);

        app(PaymentCompletionService::class)->complete($payment);

        $user->refresh();
        $this->assertSame(1, $user->event_credits);
        $this->assertSame(SubscriptionTier::Pro, $user->subscriptionTier());
        $this->assertNotNull($payment->fresh()->credits_fulfilled_at);
    }

    public function test_reverse_of_top_up_restores_previous_tier(): void
    {
        $user = User::factory()->create([
            'subscription_tier' => SubscriptionTier::Pro,
            'event_credits' => 1,
        ]);
        $payment = Payment::factory()->for($user)->completed()->create([
            'plan_key' => 'pro',
            'credits_granted' => 0,
            'amount' => 300.00,
            'credits_fulfilled_at' => now(),
            'notified_at' => now(),
            'metadata' => [
                'upgrade' => true,
                'previous_tier' => 'base',
            ],
        ]);

        app(PaymentCompletionService::class)->reverse($payment, 'refunded');

        $user->refresh();
        $this->assertSame(1, $user->event_credits);
        $this->assertSame(SubscriptionTier::Base, $user->subscriptionTier());
        $this->assertNotNull($payment->fresh()->credits_reversed_at);
    }

    public function test_reverse_of_top_up_does_not_clobber_a_later_higher_tier(): void
    {
        $user = User::factory()->proPlus()->create([
            'event_credits' => 1,
        ]);
        $payment = Payment::factory()->for($user)->completed()->create([
            'plan_key' => 'pro',
            'credits_granted' => 0,
            'amount' => 300.00,
            'credits_fulfilled_at' => now(),
            'notified_at' => now(),
            'metadata' => [
                'upgrade' => true,
                'previous_tier' => 'base',
            ],
        ]);

        app(PaymentCompletionService::class)->reverse($payment, 'refunded');

        $this->assertSame(SubscriptionTier::ProPlus, $user->fresh()->subscriptionTier());
    }
}
