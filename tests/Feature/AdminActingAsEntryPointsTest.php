<?php

namespace Tests\Feature;

use App\Enums\HelpRequestKind;
use App\Enums\HelpRequestStatus;
use App\Models\Admin;
use App\Models\AdminHelpRequest;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan: plans/admin-create-events.md (Step 4): the admin-side places an acting-as session starts
 * from, and the places that show it happened.
 */
class AdminActingAsEntryPointsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['admin.acting_as.enabled' => true]);

        $this->admin = Admin::factory()->create();
        $this->admin->assignRole('admin');
        $this->client = User::factory()->create(['status' => 'active']);
    }

    private function request(array $attributes = []): AdminHelpRequest
    {
        return AdminHelpRequest::query()->create(array_merge([
            'user_id' => $this->client->id,
            'kind' => HelpRequestKind::Other,
            'message' => 'Please help with my event.',
            'status' => HelpRequestStatus::InProgress,
            'assigned_admin_id' => $this->admin->id,
            'claimed_at' => now(),
            'access_expires_at' => now()->addDays(7),
        ], $attributes));
    }

    public function test_event_page_offers_edit_as_client_when_a_whole_account_request_is_claimed(): void
    {
        $event = Event::factory()->for($this->client)->create();
        $this->request();

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.events.show', $event))
            ->assertOk()
            ->assertSee('Edit as client')
            ->assertSee('Help request');
    }

    public function test_event_page_offers_edit_as_client_for_a_request_about_that_event(): void
    {
        $event = Event::factory()->for($this->client)->create();
        $this->request(['kind' => HelpRequestKind::EditEvent, 'event_id' => $event->id]);

        $this->actingAs($this->admin, 'admin')->get(route('admin.events.show', $event))->assertSee('Edit as client');
    }

    public function test_no_edit_as_client_without_a_covering_request(): void
    {
        $event = Event::factory()->for($this->client)->create();
        $other = Event::factory()->for($this->client)->create();

        // About a different event: shown as context, but never a way in.
        $this->request(['kind' => HelpRequestKind::EditEvent, 'event_id' => $other->id]);
        $this->actingAs($this->admin, 'admin')->get(route('admin.events.show', $event))->assertDontSee('Edit as client');

        // Open but not yet claimed.
        AdminHelpRequest::query()->delete();
        $this->request(['status' => HelpRequestStatus::Open, 'assigned_admin_id' => null, 'access_expires_at' => null]);
        $this->get(route('admin.events.show', $event))->assertDontSee('Edit as client');

        // Claimed by someone else.
        AdminHelpRequest::query()->delete();
        $this->request(['assigned_admin_id' => Admin::factory()->create()->id]);
        $this->get(route('admin.events.show', $event))->assertDontSee('Edit as client');
    }

    public function test_edit_as_client_opens_that_event_for_a_whole_account_request(): void
    {
        $event = Event::factory()->for($this->client)->create();
        $this->request();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.users.act-as', $this->client), ['event_id' => $event->id])
            ->assertRedirect(route('events.edit', $event));

        $this->assertAuthenticatedAs($this->client, 'web');
    }

    public function test_edit_as_client_refuses_an_event_that_is_not_the_clients(): void
    {
        $someoneElses = Event::factory()->create();
        $this->request();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.users.act-as', $this->client), ['event_id' => $someoneElses->id])
            ->assertSessionHas('error');

        $this->assertGuest('web');
    }

    public function test_user_page_links_to_the_current_request_and_offers_no_start_button(): void
    {
        $request = $this->request();

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.users.show', $this->client))
            ->assertOk()
            ->assertSee(route('admin.help-requests.show', $request))
            ->assertDontSee('Start acting as');
    }

    public function test_user_page_says_there_is_no_request_when_there_is_none(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.users.show', $this->client))
            ->assertSee('No open request');
    }

    public function test_help_request_page_shows_credits_with_a_grant_link(): void
    {
        $request = $this->request();

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.help-requests.show', $request))
            ->assertOk()
            ->assertSee('Grant credits')
            ->assertSee(route('admin.users.show', $this->client).'#credits-input', false);
    }

    public function test_events_index_filters_to_events_created_by_an_admin(): void
    {
        $byAdmin = Event::factory()->for($this->client)->create(['name' => 'Set Up By Team']);
        $byAdmin->forceFill(['created_by_admin_id' => $this->admin->id])->save();
        Event::factory()->create(['name' => 'Made By Client']);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.events.index', ['created_by_admin' => 1]))
            ->assertOk()
            ->assertSee('Set Up By Team')
            ->assertDontSee('Made By Client');

        $this->get(route('admin.events.index'))->assertSee('Set Up By Team')->assertSee('Made By Client');
    }
}
