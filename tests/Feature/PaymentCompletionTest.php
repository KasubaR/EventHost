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
}
