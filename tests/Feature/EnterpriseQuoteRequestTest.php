<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\EnterpriseQuoteRequest;
use App\Models\User;
use App\Notifications\EnterpriseQuoteRequestedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EnterpriseQuoteRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_host_can_request_an_enterprise_quote(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'phone' => '0961234567',
            'company_name' => 'Acme Events',
        ]);

        $this->actingAs($user)
            ->post(route('billing.enterprise-request.store'), [
                'message' => 'We need multi-page invitation sites for 200 guests.',
            ])
            ->assertRedirect(route('billing.show'))
            ->assertSessionHas('status', 'enterprise-request-submitted');

        $request = EnterpriseQuoteRequest::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($request);
        $this->assertSame('We need multi-page invitation sites for 200 guests.', $request->message);
        $this->assertNull($request->dismissed_at);

        Notification::assertSentOnDemand(
            EnterpriseQuoteRequestedNotification::class,
            function (EnterpriseQuoteRequestedNotification $notification, array $channels, object $notifiable) use ($user): bool {
                return $notifiable->routes['mail'] === config('mail.support_address')
                    && $notification->user->id === $user->id;
            }
        );
    }

    public function test_the_message_is_optional(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('billing.enterprise-request.store'), [])
            ->assertRedirect(route('billing.show'));

        $request = EnterpriseQuoteRequest::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($request);
        $this->assertNull($request->message);
    }

    public function test_a_second_request_is_rejected_while_one_is_pending(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('billing.enterprise-request.store'), ['message' => 'First ask']);

        $this->actingAs($user)
            ->post(route('billing.enterprise-request.store'), ['message' => 'Second ask'])
            ->assertSessionHasErrors('enterprise_request');

        $this->assertSame(1, EnterpriseQuoteRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->post(route('billing.enterprise-request.store'), ['message' => 'Hello'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('enterprise_quote_requests', 0);
    }

    public function test_billing_page_shows_pending_state_instead_of_the_form(): void
    {
        $user = User::factory()->create();
        EnterpriseQuoteRequest::query()->create(['user_id' => $user->id, 'message' => 'Please call me']);

        $this->actingAs($user)
            ->get(route('billing.show'))
            ->assertOk()
            ->assertSee('Request sent', false)
            ->assertDontSee('name="message"', false);
    }

    public function test_creating_a_custom_quote_auto_dismisses_the_pending_request(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $user = User::factory()->create();
        $request = EnterpriseQuoteRequest::query()->create(['user_id' => $user->id, 'message' => 'Please call me']);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.custom-quote.store', $user), [
                'amount' => 15000,
                'credits_granted' => 3,
            ])
            ->assertRedirect(route('admin.users.show', $user));

        $this->assertNotNull($request->fresh()->dismissed_at);
    }
}
