<?php

namespace Tests\Feature\Api\V1\Events;

use App\Enums\EventProductKind;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function invitationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Party',
            'event_type' => 'birthday',
            'product_kind' => EventProductKind::Invitation->value,
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '18:00',
        ], $overrides);
    }

    public function test_invitation_event_is_created_as_a_draft_and_needs_a_template(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/host/events', $this->invitationPayload());

        $response->assertCreated()
            ->assertJsonPath('next_step', 'choose_template')
            ->assertJsonPath('event.is_published', false)
            ->assertJsonPath('event.product_kind', 'invitation');

        $this->assertSame(1, Event::where('user_id', $user->id)->count());
    }

    public function test_invitation_event_with_a_usable_preferred_template_goes_straight_to_edit(): void
    {
        $user = User::factory()->create(); // Base tier
        $template = InvitationTemplate::factory()->create(); // min tier Base

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/host/events', $this->invitationPayload([
                'preferred_invitation_template_id' => $template->id,
            ]));

        $response->assertCreated()->assertJsonPath('next_step', 'edit');
        $this->assertSame($template->id, $response->json('event.invitation_template_id'));
    }

    public function test_ticketed_event_returns_ticket_setup_next_step(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/host/events', $this->invitationPayload([
                'product_kind' => EventProductKind::Ticketed->value,
                'event_type' => 'concert',
            ]));

        $response->assertCreated()
            ->assertJsonPath('next_step', 'ticket_setup')
            ->assertJsonPath('event.product_kind', 'ticketed')
            ->assertJsonPath('event.is_public', true);
    }

    public function test_draft_limit_returns_409_with_a_machine_code(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->count(Event::MAX_OPEN_DRAFTS)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/host/events', $this->invitationPayload());

        $response->assertStatus(409)
            ->assertJsonPath('error', 'draft_limit_reached')
            ->assertJsonPath('limit', Event::MAX_OPEN_DRAFTS);
    }

    public function test_cover_image_upload_is_stored_and_attached(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->post('/api/v1/host/events', array_merge($this->invitationPayload(), [
                'cover_image' => UploadedFile::fake()->image('cover.jpg', 1200, 630),
            ]));

        $response->assertCreated();
        $event = Event::findOrFail($response->json('event.id'));
        $this->assertNotNull($event->cover_image);
        Storage::disk('public')->assertExists($event->cover_image);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/host/events', $this->invitationPayload())->assertUnauthorized();
    }
}
