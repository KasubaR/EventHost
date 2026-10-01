<?php

namespace Tests\Feature;

use App\Enums\HelpRequestKind;
use App\Enums\HelpRequestStatus;
use App\Models\Admin;
use App\Models\AdminHelpRequest;
use App\Models\Event;
use App\Models\User;
use App\Notifications\HelpRequestClaimedNotification;
use App\Notifications\HelpRequestDeclinedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Plan: plans/admin-create-events.md (Step 0). The help request is the consent gate for
 * acting as a client, so most of these run with `require_help_request` at its default (on).
 */
class HelpRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['admin.acting_as.enabled' => true]);
    }

    private function adminWith(string $role = 'admin'): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole($role);

        return $admin;
    }

    private function client(): User
    {
        return User::factory()->create(['status' => 'active']);
    }

    private function helpRequest(User $client, array $attributes = []): AdminHelpRequest
    {
        return AdminHelpRequest::query()->create(array_merge([
            'user_id' => $client->id,
            'kind' => HelpRequestKind::CreateEvent,
            'message' => 'Please set up my wedding invitation.',
            'status' => HelpRequestStatus::Open,
        ], $attributes));
    }

    private function claimed(User $client, Admin $admin, array $attributes = []): AdminHelpRequest
    {
        return $this->helpRequest($client, array_merge([
            'status' => HelpRequestStatus::InProgress,
            'assigned_admin_id' => $admin->id,
            'claimed_at' => now(),
            'access_expires_at' => now()->addDays(7),
        ], $attributes));
    }

    private function startActingAs(Admin $admin, User $client)
    {
        $response = $this->actingAs($admin, 'admin')->post(route('admin.users.act-as', $client));
        auth()->shouldUse('web');

        return $response;
    }

    // ── Client side ───────────────────────────────────────────────────────────

    public function test_client_can_send_a_request(): void
    {
        $client = $this->client();

        $this->actingAs($client)->post(route('help-request.store'), [
            'kind' => 'create_event',
            'message' => 'Please set up a graduation invitation for me.',
            'contact_preference' => 'WhatsApp evenings',
        ])->assertRedirect(route('help-request.show'));

        $this->assertDatabaseHas('admin_help_requests', [
            'user_id' => $client->id,
            'kind' => 'create_event',
            'status' => 'open',
            'event_id' => null,
        ]);
    }

    public function test_only_one_request_can_be_open_at_a_time(): void
    {
        $client = $this->client();
        $this->helpRequest($client);

        $this->actingAs($client)->post(route('help-request.store'), [
            'kind' => 'other',
            'message' => 'A second request that should be refused.',
        ])->assertSessionHas('error');

        $this->assertSame(1, AdminHelpRequest::query()->count());
    }

    public function test_an_expired_request_frees_the_slot(): void
    {
        $client = $this->client();
        $old = $this->claimed($client, $this->adminWith(), ['access_expires_at' => now()->subMinute()]);

        $this->actingAs($client)->post(route('help-request.store'), [
            'kind' => 'other',
            'message' => 'Sending a fresh request after the last expired.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(HelpRequestStatus::Expired, $old->fresh()->status);
        $this->assertSame(2, AdminHelpRequest::query()->count());
    }

    public function test_an_edit_request_must_name_one_of_the_clients_own_events(): void
    {
        $client = $this->client();
        $someoneElses = Event::factory()->create();

        $this->actingAs($client)->post(route('help-request.store'), [
            'kind' => 'edit_event',
            'event_id' => $someoneElses->id,
            'message' => 'Fix the venue on this event for me please.',
        ])->assertSessionHasErrors('event_id');

        $this->assertSame(0, AdminHelpRequest::query()->count());
    }

    public function test_client_can_cancel_but_not_someone_elses_request(): void
    {
        $client = $this->client();
        $mine = $this->helpRequest($client);
        $theirs = $this->helpRequest($this->client());

        $this->actingAs($client)->delete(route('help-request.destroy', $theirs))->assertNotFound();
        $this->delete(route('help-request.destroy', $mine))->assertRedirect(route('help-request.show'));

        $this->assertSame(HelpRequestStatus::Cancelled, $mine->fresh()->status);
        $this->assertSame(HelpRequestStatus::Open, $theirs->fresh()->status);
    }

    public function test_the_request_page_renders(): void
    {
        $client = $this->client();
        $this->helpRequest($client);

        $this->actingAs($client)->get(route('help-request.show'))->assertOk()->assertSee('Cancel request');
    }

    // ── Admin side ────────────────────────────────────────────────────────────

    public function test_support_role_cannot_see_the_queue(): void
    {
        $this->actingAs($this->adminWith('support'), 'admin')
            ->get(route('admin.help-requests.index'))
            ->assertForbidden();
    }

    public function test_admin_can_see_the_queue_and_a_request(): void
    {
        $request = $this->helpRequest($this->client());

        $this->actingAs($this->adminWith(), 'admin')->get(route('admin.help-requests.index'))->assertOk()->assertSee('#'.$request->id);
        $this->get(route('admin.help-requests.show', $request))->assertOk()->assertSee('Claim this request');
    }

    public function test_claiming_assigns_the_admin_opens_the_window_and_emails_the_client(): void
    {
        Notification::fake();
        $admin = $this->adminWith();
        $client = $this->client();
        $request = $this->helpRequest($client);

        $this->actingAs($admin, 'admin')->post(route('admin.help-requests.claim', $request))->assertSessionHas('status');

        $request->refresh();
        $this->assertSame(HelpRequestStatus::InProgress, $request->status);
        $this->assertSame($admin->id, $request->assigned_admin_id);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $request->access_expires_at->timestamp, 5);
        Notification::assertSentTo($client, HelpRequestClaimedNotification::class);
    }

    public function test_a_request_can_only_be_claimed_once(): void
    {
        $first = $this->adminWith();
        $second = $this->adminWith();
        $request = $this->helpRequest($this->client());

        $this->actingAs($first, 'admin')->post(route('admin.help-requests.claim', $request));
        $this->actingAs($second, 'admin')->post(route('admin.help-requests.claim', $request))->assertSessionHas('error');

        $this->assertSame($first->id, $request->fresh()->assigned_admin_id);
    }

    public function test_declining_notifies_the_client_with_the_reason(): void
    {
        Notification::fake();
        $client = $this->client();
        $request = $this->helpRequest($client);

        $this->actingAs($this->adminWith(), 'admin')
            ->post(route('admin.help-requests.decline', $request), ['decline_note' => 'We do not cover that event type.'])
            ->assertSessionHas('status');

        $this->assertSame(HelpRequestStatus::Declined, $request->fresh()->status);
        Notification::assertSentTo($client, HelpRequestDeclinedNotification::class);
    }

    public function test_only_the_assignee_or_a_super_admin_can_complete(): void
    {
        $assignee = $this->adminWith();
        $request = $this->claimed($this->client(), $assignee);

        $this->actingAs($this->adminWith(), 'admin')->post(route('admin.help-requests.complete', $request))->assertForbidden();
        $this->actingAs($assignee, 'admin')->post(route('admin.help-requests.complete', $request))->assertSessionHas('status');

        $this->assertSame(HelpRequestStatus::Completed, $request->fresh()->status);
    }

    // ── The consent gate ──────────────────────────────────────────────────────

    public function test_acting_as_is_refused_without_a_claimed_request(): void
    {
        $client = $this->client();
        $this->helpRequest($client); // open, not claimed

        $this->startActingAs($this->adminWith(), $client)->assertSessionHas('error');

        $this->assertGuest('web');
    }

    public function test_acting_as_is_refused_when_another_admin_holds_the_request(): void
    {
        $client = $this->client();
        $this->claimed($client, $this->adminWith());

        $this->startActingAs($this->adminWith(), $client)->assertSessionHas('error');

        $this->assertGuest('web');
    }

    public function test_acting_as_works_for_the_assigned_admin_and_lands_on_the_wizard(): void
    {
        $admin = $this->adminWith();
        $client = $this->client();
        $this->claimed($client, $admin);

        $this->startActingAs($admin, $client)->assertRedirect(route('events.create'));

        $this->assertAuthenticatedAs($client, 'web');
    }

    public function test_acting_as_for_an_event_request_lands_on_that_events_edit_page(): void
    {
        $admin = $this->adminWith();
        $client = $this->client();
        $event = Event::factory()->create(['user_id' => $client->id]);
        $this->claimed($client, $admin, ['kind' => HelpRequestKind::EditEvent, 'event_id' => $event->id]);

        $this->startActingAs($admin, $client)->assertRedirect(route('events.edit', $event));
    }

    public function test_an_event_scoped_session_cannot_open_other_events_or_create_new_ones(): void
    {
        $admin = $this->adminWith();
        $client = $this->client();
        $event = Event::factory()->create(['user_id' => $client->id]);
        $other = Event::factory()->create(['user_id' => $client->id]);
        $this->claimed($client, $admin, ['kind' => HelpRequestKind::EditEvent, 'event_id' => $event->id]);

        $this->startActingAs($admin, $client);

        $this->get(route('events.edit', $event))->assertOk();
        $this->get(route('events.edit', $other))->assertForbidden();
        $this->get(route('events.create'))->assertForbidden();
    }

    public function test_cancelling_the_request_ends_a_live_session(): void
    {
        $admin = $this->adminWith();
        $client = $this->client();
        $request = $this->claimed($client, $admin);
        $this->startActingAs($admin, $client);

        $request->update(['status' => HelpRequestStatus::Cancelled, 'access_expires_at' => now()]);

        $this->get(route('events.index'))->assertRedirect(route('admin.help-requests.show', $request));
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_the_request_window_running_out_ends_a_live_session(): void
    {
        $admin = $this->adminWith();
        $client = $this->client();
        $this->claimed($client, $admin, ['access_expires_at' => now()->addMinutes(5)]);
        $this->startActingAs($admin, $client);

        $this->travel(10)->minutes();

        $this->get(route('events.index'))->assertRedirect();
        $this->assertGuest('web');
    }

    public function test_the_client_cannot_send_or_cancel_a_request_while_an_admin_is_acting_as_them(): void
    {
        $admin = $this->adminWith();
        $client = $this->client();
        $request = $this->claimed($client, $admin);
        $this->startActingAs($admin, $client);

        $this->delete(route('help-request.destroy', $request))->assertForbidden();
        $this->post(route('help-request.store'), ['kind' => 'other', 'message' => 'Should never be accepted.'])->assertForbidden();

        $this->assertSame(HelpRequestStatus::InProgress, $request->fresh()->status);
    }
}
