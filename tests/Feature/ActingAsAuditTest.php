<?php

namespace Tests\Feature;

use App\Enums\HelpRequestKind;
use App\Enums\HelpRequestStatus;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\AdminHelpRequest;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan: plans/admin-create-events.md (Step 3). Runs with the consent gate on, like production.
 */
class ActingAsAuditTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private User $client;

    private AdminHelpRequest $helpRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['admin.acting_as.enabled' => true]);

        $this->admin = Admin::factory()->create();
        $this->admin->assignRole('admin');
        $this->client = User::factory()->withCredits(1)->create(['status' => 'active']);
        $this->helpRequest = AdminHelpRequest::query()->create([
            'user_id' => $this->client->id,
            'kind' => HelpRequestKind::CreateEvent,
            'message' => 'Please set up my event.',
            'status' => HelpRequestStatus::InProgress,
            'assigned_admin_id' => $this->admin->id,
            'claimed_at' => now(),
            'access_expires_at' => now()->addDays(7),
        ]);
    }

    private function start(): void
    {
        $this->actingAs($this->admin, 'admin')->post(route('admin.users.act-as', $this->client));
        auth()->shouldUse('web');
    }

    private function storePayload(): array
    {
        return [
            'name' => 'Chanda and Mwila',
            'event_type' => 'birthday',
            'audience' => 'private',
            'product_kind' => 'invitation',
            'host_contact_phone' => '0977123456',
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:30',
            'venue' => 'Garden Terrace',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ];
    }

    public function test_session_start_and_stop_are_logged(): void
    {
        $this->start();
        $this->delete(route('admin.acting-as.destroy'));

        $rows = AdminActivityLog::query()->orderBy('id')->get();

        $this->assertSame(['session_started', 'session_ended'], $rows->pluck('action')->all());
        $this->assertSame($this->admin->id, $rows[0]->admin_id);
        $this->assertSame($this->client->id, $rows[0]->user_id);
        $this->assertSame($this->helpRequest->id, $rows[0]->help_request_id);
        $this->assertSame('exited', $rows[1]->properties['reason']);
    }

    public function test_a_timed_out_session_is_logged_with_its_reason(): void
    {
        $this->start();
        $this->travel(61)->minutes();

        $this->get(route('events.index'));

        $this->assertSame('The session timed out.', AdminActivityLog::query()->where('action', 'session_ended')->value('properties')['reason']);
    }

    public function test_an_event_created_while_acting_belongs_to_the_client_and_records_the_admin(): void
    {
        $this->start();

        $this->post(route('events.store'), $this->storePayload())->assertSessionHasNoErrors();

        $event = Event::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame($this->client->id, $event->user_id);
        $this->assertSame($this->admin->id, $event->created_by_admin_id);
        $this->assertDatabaseHas('admin_activity_log', [
            'action' => 'event_created',
            'event_id' => $event->id,
            'admin_id' => $this->admin->id,
            'user_id' => $this->client->id,
        ]);
        $this->assertSame(1, AdminActivityLog::query()->where('action', 'event_created')->count());
    }

    public function test_an_event_created_by_the_client_themselves_has_no_admin_provenance(): void
    {
        $this->actingAs($this->client)->post(route('events.store'), $this->storePayload());

        $this->assertNull(Event::query()->where('user_id', $this->client->id)->firstOrFail()->created_by_admin_id);
        $this->assertSame(0, AdminActivityLog::query()->count());
    }

    public function test_publishing_is_logged_and_the_credit_is_taken_from_the_client(): void
    {
        $event = Event::factory()->for($this->client)->create(['is_published' => false]);
        $this->start();

        $this->patch(route('events.publish', $event))->assertRedirect(route('events.index'));

        $this->assertSame(0, $this->client->fresh()->event_credits);
        $this->assertDatabaseHas('admin_activity_log', ['action' => 'event_published', 'event_id' => $event->id]);

        $spent = AdminActivityLog::query()->where('action', 'credits_spent')->firstOrFail();
        $this->assertSame(['before' => 1, 'after' => 0], $spent->properties);
        $this->assertSame($event->id, $spent->event_id);
    }

    public function test_updating_and_deleting_are_logged(): void
    {
        $event = Event::factory()->for($this->client)->create();
        $this->start();

        $this->delete(route('events.destroy', $event));

        $this->assertDatabaseHas('admin_activity_log', ['action' => 'event_deleted', 'event_id' => $event->id]);
    }

    public function test_reads_and_rejected_requests_are_not_logged(): void
    {
        $this->start();

        $this->get(route('events.index'));
        $this->post(route('events.store'), ['name' => ''])->assertSessionHasErrors();

        $this->assertSame(['session_started'], AdminActivityLog::query()->pluck('action')->all());
    }

    public function test_the_admin_user_page_shows_the_trail(): void
    {
        $this->start();
        $this->post(route('events.store'), $this->storePayload());
        $this->delete(route('admin.acting-as.destroy'));

        $this->get(route('admin.users.show', $this->client))
            ->assertOk()
            ->assertSee('Team activity on the client')
            ->assertSee('Created an event')
            ->assertSee($this->admin->name);
    }

    public function test_the_admin_event_page_says_it_was_created_on_the_clients_behalf(): void
    {
        $this->start();
        $this->post(route('events.store'), $this->storePayload());
        $this->delete(route('admin.acting-as.destroy'));
        $event = Event::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->get(route('admin.events.show', $event))
            ->assertOk()
            ->assertSee('Created by admin '.$this->admin->name.' on behalf of the client')
            ->assertSee('Created an event');
    }

    public function test_the_client_can_see_what_the_team_did(): void
    {
        $this->start();
        $this->post(route('events.store'), $this->storePayload());
        $this->delete(route('admin.acting-as.destroy'));

        auth()->shouldUse('web');
        $this->flushSession();

        $this->actingAs($this->client)
            ->get(route('help-request.show'))
            ->assertOk()
            ->assertSee('What our team did')
            ->assertSee('Created an event');
    }

    public function test_the_trail_survives_deleting_the_event(): void
    {
        $this->start();
        $this->post(route('events.store'), $this->storePayload());
        $event = Event::query()->where('user_id', $this->client->id)->firstOrFail();

        $event->forceDelete();

        $this->assertDatabaseHas('admin_activity_log', ['action' => 'event_created', 'event_id' => null]);
    }
}
