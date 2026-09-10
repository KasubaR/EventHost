<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Notifications\ContributionReceiptNotification;
use App\Notifications\NewContributionReceivedNotification;
use App\Services\LencoService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

/**
 * Phase 3 of plans/contributions.md — contributor receipt + host
 * notification, sent once a ContributionPayment settles.
 */
class ContributionNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['services.lenco.api_secret_key' => 'test-contribution-notify-secret']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockSuccessfulLenco(): void
    {
        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_ctb_notify',
            'lencoReference' => 'LEN-CN',
            'status' => 'successful',
            'amount' => 100.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);
    }

    public function test_contributor_and_host_both_get_notified_when_a_payment_completes(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create([
            'is_published' => true,
            'is_public' => true,
            'contribution_enabled' => true,
            'contribution_amount' => '100.00',
        ]);

        $this->mockSuccessfulLenco();

        $this->postJson(route('events.public.contribute.store', $event->slug), [
            'name' => 'Notify Test',
            'phone' => '0961234567',
            'email' => 'contributor@example.com',
            'amount' => '100.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        Notification::assertSentOnDemand(
            ContributionReceiptNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'contributor@example.com'
        );

        Notification::assertSentTo($owner, NewContributionReceivedNotification::class);
    }

    public function test_no_receipt_is_sent_when_the_contributor_gave_no_email(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create([
            'is_published' => true,
            'is_public' => true,
            'contribution_enabled' => true,
            'contribution_amount' => '100.00',
        ]);

        $this->mockSuccessfulLenco();

        $this->postJson(route('events.public.contribute.store', $event->slug), [
            'name' => 'No Email Test',
            'phone' => '0961234567',
            'amount' => '100.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        Notification::assertSentOnDemandTimes(ContributionReceiptNotification::class, 0);
        Notification::assertSentTo($owner, NewContributionReceivedNotification::class);
    }

    public function test_host_is_not_notified_when_they_turned_off_contribution_updates(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $owner->notification_preferences = array_merge(
            $owner->notification_preferences,
            ['email_contribution_updates' => false]
        );
        $owner->save();

        $event = Event::factory()->for($owner)->create([
            'is_published' => true,
            'is_public' => true,
            'contribution_enabled' => true,
            'contribution_amount' => '100.00',
        ]);

        $this->mockSuccessfulLenco();

        $this->postJson(route('events.public.contribute.store', $event->slug), [
            'name' => 'Opted Out Host Test',
            'phone' => '0961234567',
            'email' => 'contributor2@example.com',
            'amount' => '100.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        Notification::assertSentOnDemand(ContributionReceiptNotification::class);
        Notification::assertNotSentTo($owner, NewContributionReceivedNotification::class);
    }
}
