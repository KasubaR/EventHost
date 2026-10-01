<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActingAsClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        config([
            'admin.acting_as.enabled' => true,
            'admin.acting_as.require_help_request' => false,
        ]);
    }

    private function adminWith(string $role): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole($role);

        return $admin;
    }

    private function client(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['status' => 'active'], $attributes));
    }

    private function start(Admin $admin, User $client): void
    {
        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.act-as', $client))
            ->assertRedirect(route('events.index'));

        // actingAs() makes `admin` the default guard for the rest of the test; a real
        // request always starts on `web`.
        auth()->shouldUse('web');
    }

    public function test_admin_can_start_acting_as_a_client_and_the_web_guard_becomes_the_client(): void
    {
        $admin = $this->adminWith('admin');
        $client = $this->client();

        $this->start($admin, $client);

        $this->assertAuthenticatedAs($client, 'web');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->get(route('events.index'))->assertOk();
    }

    public function test_starting_does_not_touch_last_login_columns(): void
    {
        $client = $this->client(['last_login_at' => null, 'last_login_ip' => null]);

        $this->start($this->adminWith('admin'), $client);

        $client->refresh();
        $this->assertNull($client->last_login_at);
        $this->assertNull($client->last_login_ip);
    }

    public function test_support_role_cannot_act_as_a_client(): void
    {
        $this->actingAs($this->adminWith('support'), 'admin')
            ->post(route('admin.users.act-as', $this->client()))
            ->assertForbidden();

        $this->assertGuest('web');
    }

    public function test_it_is_refused_while_switched_off(): void
    {
        config(['admin.acting_as.enabled' => false]);

        $this->actingAs($this->adminWith('admin'), 'admin')
            ->post(route('admin.users.act-as', $this->client()))
            ->assertSessionHas('error');

        $this->assertGuest('web');
    }

    public function test_it_is_refused_until_a_help_request_exists(): void
    {
        config(['admin.acting_as.require_help_request' => true]);

        $this->actingAs($this->adminWith('admin'), 'admin')
            ->post(route('admin.users.act-as', $this->client()))
            ->assertSessionHas('error');

        $this->assertGuest('web');
    }

    public function test_suspended_pending_and_unverified_clients_are_refused(): void
    {
        $admin = $this->adminWith('admin');

        foreach ([
            $this->client(['status' => 'suspended']),
            $this->client(['status' => 'pending']),
            $this->client(['email_verified_at' => null]),
        ] as $client) {
            $this->actingAs($admin, 'admin')
                ->post(route('admin.users.act-as', $client))
                ->assertSessionHas('error');

            $this->assertGuest('web');
        }
    }

    public function test_a_staff_identity_cannot_be_acted_on(): void
    {
        $linked = $this->client();
        Admin::factory()->create(['user_id' => $linked->id]);

        $this->actingAs($this->adminWith('admin'), 'admin')
            ->post(route('admin.users.act-as', $linked))
            ->assertSessionHas('error');

        $this->assertGuest('web');
    }

    public function test_blocked_routes_return_403_while_acting(): void
    {
        $this->start($this->adminWith('admin'), $this->client());

        $this->get(route('settings.security.edit'))->assertForbidden();
        $this->get(route('settings.account.edit'))->assertForbidden();
        $this->delete(route('settings.account.destroy'))->assertForbidden();
        $this->put(route('password.update'), [])->assertForbidden();
        $this->get(route('billing.show'))->assertForbidden();
        $this->post(route('payment.initiate'), [])->assertForbidden();
        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_email_cannot_be_changed_while_acting(): void
    {
        $client = $this->client();
        $this->start($this->adminWith('admin'), $client);

        $this->patch(route('settings.profile.update'), [
            'name' => $client->name,
            'email' => 'someone-else@example.com',
        ])->assertSessionHasErrors('email');

        $this->assertSame($client->email, $client->fresh()->email);
    }

    public function test_exit_returns_to_the_client_page_and_keeps_the_admin_signed_in(): void
    {
        $admin = $this->adminWith('admin');
        $client = $this->client();
        $this->start($admin, $client);

        $this->delete(route('admin.acting-as.destroy'))->assertRedirect(route('admin.users.show', $client));

        $this->assertGuest('web');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_ordinary_logout_while_acting_only_leaves_the_client(): void
    {
        $admin = $this->adminWith('admin');
        $this->start($admin, $this->client());

        $this->post(route('logout'))->assertRedirect();

        $this->assertGuest('web');
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_the_session_ends_after_the_time_limit(): void
    {
        config(['admin.acting_as.ttl_minutes' => 60]);
        $admin = $this->adminWith('admin');
        $this->start($admin, $this->client());

        $this->travel(61)->minutes();

        $this->get(route('events.index'))->assertRedirect();

        $this->assertGuest('web');
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_the_session_ends_when_the_client_is_suspended(): void
    {
        $client = $this->client();
        $this->start($this->adminWith('admin'), $client);

        $client->forceFill(['status' => 'suspended'])->save();
        // The test app reuses one guard instance across requests; a real request reloads the user.
        auth()->guard('web')->forgetUser();

        $this->get(route('events.index'))->assertRedirect();
        $this->assertGuest('web');
    }

    public function test_the_client_session_ends_when_the_admin_session_does(): void
    {
        $this->start($this->adminWith('admin'), $this->client());

        auth('admin')->logout();

        $this->get(route('events.index'))->assertRedirect(route('admin.login'));
        $this->assertGuest('web');
    }
}
