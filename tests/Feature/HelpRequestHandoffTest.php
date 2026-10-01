<?php

namespace Tests\Feature;

use App\Enums\HelpRequestKind;
use App\Enums\HelpRequestStatus;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\AdminHelpRequest;
use App\Models\Event;
use App\Models\User;
use App\Notifications\HelpRequestCompletedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Plan: plans/admin-create-events.md (Step 6): the completion email and the "Set up by our team" badge.
 */
class HelpRequestHandoffTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private User $client;

    private AdminHelpRequest $helpRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = Admin::factory()->create();
        $this->admin->assignRole('admin');
        $this->client = User::factory()->create(['status' => 'active']);
        $this->helpRequest = AdminHelpRequest::query()->create([
            'user_id' => $this->client->id,
            'kind' => HelpRequestKind::CreateEvent,
            'message' => 'Please set up my wedding.',
            'status' => HelpRequestStatus::InProgress,
            'assigned_admin_id' => $this->admin->id,
            'claimed_at' => now(),
            'access_expires_at' => now()->addDays(7),
        ]);
    }

    private function log(string $action, ?Event $event = null): void
    {
        AdminActivityLog::query()->create([
            'admin_id' => $this->admin->id,
            'user_id' => $this->client->id,
            'event_id' => $event?->id,
            'help_request_id' => $this->helpRequest->id,
            'action' => $action,
        ]);
    }

    public function test_completing_emails_the_client(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.help-requests.complete', $this->helpRequest))
            ->assertSessionHas('status');

        Notification::assertSentTo($this->client, HelpRequestCompletedNotification::class);
    }

    public function test_completing_twice_emails_only_once(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'admin')->post(route('admin.help-requests.complete', $this->helpRequest));
        $this->post(route('admin.help-requests.complete', $this->helpRequest))->assertSessionHas('error');

        Notification::assertSentToTimes($this->client, HelpRequestCompletedNotification::class, 1);
    }

    public function test_the_email_lists_what_was_done_links_the_event_and_says_what_is_next(): void
    {
        $event = Event::factory()->for($this->client)->create(['name' => 'Chanda and Mwila', 'is_published' => false]);
        $this->log('session_started');
        $this->log('event_created', $event);
        $this->log('event_updated', $event);
        $this->log('event_updated', $event);
        $this->log('session_ended');

        $mail = (new HelpRequestCompletedNotification($this->helpRequest))->toMail($this->client);
        $text = implode("\n", array_merge($mail->introLines, $mail->outroLines));

        $this->assertSame('Your request is complete', $mail->subject);
        $this->assertStringContainsString('- Created an event: Chanda and Mwila', $text);
        $this->assertSame(1, substr_count($text, '- Updated an event'), 'repeated actions are listed once');
        $this->assertStringNotContainsString('Started acting', $text);
        $this->assertStringContainsString('publish it yourself', $text);
        $this->assertSame(route('events.show', $event, absolute: true), $mail->actionUrl);
    }

    public function test_next_step_is_the_clients_payment_for_an_approved_free_registration_event(): void
    {
        $event = Event::factory()->for($this->client)->publicAudience()->create([
            'name' => 'Open Day',
            'public_registration_status' => 'approved',
            'public_registration_quote_amount' => 150,
        ]);
        $this->log('event_created', $event);

        $mail = (new HelpRequestCompletedNotification($this->helpRequest))->toMail($this->client);

        $this->assertStringContainsString('Pay the quoted amount', implode("\n", $mail->introLines));
    }

    public function test_the_email_still_sends_when_nothing_was_changed(): void
    {
        $mail = (new HelpRequestCompletedNotification($this->helpRequest))->toMail($this->client);

        $this->assertSame(route('events.index', absolute: true), $mail->actionUrl);
        $this->assertStringNotContainsString('What we did', implode("\n", $mail->introLines));
    }

    public function test_the_badge_shows_on_an_event_the_team_created_and_not_on_others(): void
    {
        $teams = Event::factory()->for($this->client)->privateAudience()->create(['name' => 'Team Made']);
        $teams->forceFill(['created_by_admin_id' => $this->admin->id])->save();
        Event::factory()->for($this->client)->privateAudience()->create(['name' => 'Self Made']);

        $response = $this->actingAs($this->client)->get(route('events.index'))->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'Set up by our team'));

        $this->get(route('events.edit', $teams))->assertSee('Set up by our team');
        $this->get(route('events.show', $teams))->assertSee('Set up by our team');
    }

    public function test_the_badge_is_absent_when_the_client_made_the_event(): void
    {
        $event = Event::factory()->for($this->client)->privateAudience()->create();

        $this->actingAs($this->client)->get(route('events.edit', $event))->assertDontSee('Set up by our team');
    }
}
