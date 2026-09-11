<?php

namespace Tests\Feature\Api\V1\Design;

use App\Jobs\ProcessInvitationDesignImageJob;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\StagedMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function eventFor(User $user, string $templateSlug = 'slate-minimal'): Event
    {
        $tpl = InvitationTemplate::query()->where('slug', $templateSlug)->firstOrFail();

        return Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function designPayload(Event $event, array $overrides = []): array
    {
        $tpl = $event->invitationTemplate ?? InvitationTemplate::findOrFail($event->invitation_template_id);
        $order = collect($tpl->default_sections)->pluck('type')->values()->all();
        $visibility = [];
        foreach ($order as $type) {
            $visibility[$type] = '1';
        }

        return array_merge([
            'font_heading_key' => 'inter',
            'font_body_key' => 'inter',
            'animation_subtle' => '0',
            'countdown_enabled' => '1',
            'section_order' => $order,
            'section_visible' => $visibility,
            'clear_video' => '0',
            'clear_audio' => '0',
            'content_story' => '',
            'schedule_items' => [],
            'rsvp_form' => [
                'message' => ['visible' => '1', 'label' => 'Message to host'],
                'meal_preference' => ['visible' => '1', 'label' => 'Meal preference'],
                'transportation_note' => ['visible' => '1', 'label' => 'Transportation notes'],
                'song_request' => ['visible' => '1', 'label' => 'Song request'],
            ],
        ], $overrides);
    }

    public function test_saving_with_a_staged_gallery_image_consumes_it_and_dispatches_the_webp_job(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $staged = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/host/events/{$event->id}/media", [
                'slot' => StagedMedia::SLOT_GALLERY,
                'file' => UploadedFile::fake()->image('party.jpg', 400, 300),
            ])->assertCreated()->json();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson(
                "/api/v1/host/events/{$event->id}/design",
                $this->designPayload($event, ['staged_media' => [$staged['id']]])
            );

        $response->assertOk()->assertJsonPath('status', 'invitation-design-saved');

        $event->refresh();
        $gallery = $event->invitation_customization['media']['gallery'] ?? [];
        $this->assertCount(1, $gallery);
        $this->assertNull(StagedMedia::query()->find($staged['id']));

        Queue::assertPushed(ProcessInvitationDesignImageJob::class);
    }

    public function test_direct_multipart_gallery_upload_is_accepted(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patch(
                "/api/v1/host/events/{$event->id}/design",
                $this->designPayload($event, [
                    'gallery_images' => [UploadedFile::fake()->image('direct.jpg', 400, 300)],
                ])
            );

        $response->assertOk();
        $event->refresh();
        $this->assertCount(1, $event->invitation_customization['media']['gallery']);
    }

    public function test_stale_customization_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson(
                "/api/v1/host/events/{$event->id}/design",
                $this->designPayload($event, ['customization_token' => 'not-the-real-token'])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customization_token');
    }

    public function test_gallery_cap_counts_staged_against_saved(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $existing = [];
        for ($i = 0; $i < 5; $i++) {
            $path = 'invitation-gallery/'.$event->id.'/saved'.$i.'.webp';
            Storage::disk('public')->put($path, 'x');
            $existing[] = $path;
        }
        $event->invitation_customization = ['media' => ['gallery' => $existing]];
        $event->save();

        $ids = [];
        foreach (['a', 'b'] as $name) {
            $ids[] = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
                ->postJson("/api/v1/host/events/{$event->id}/media", [
                    'slot' => StagedMedia::SLOT_GALLERY,
                    'file' => UploadedFile::fake()->image($name.'.jpg', 80, 80),
                ])->assertCreated()->json('id');
        }

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson(
                "/api/v1/host/events/{$event->id}/design",
                $this->designPayload($event, ['staged_media' => $ids])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gallery_images');
    }

    public function test_beauty_for_ashes_positional_speaker_slot_replace(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user, 'beauty-for-ashes');

        $staged = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/host/events/{$event->id}/media", [
                'slot' => StagedMedia::speakerSlot(2),
                'file' => UploadedFile::fake()->image('speaker2.jpg', 200, 300),
            ])->assertCreated()->json();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson(
                "/api/v1/host/events/{$event->id}/design",
                $this->designPayload($event, ['staged_media' => [$staged['id']]])
            )->assertOk();

        $event->refresh();
        $couple = $event->invitation_customization['media']['couple_photos'];
        $this->assertCount(4, $couple);
        $this->assertSame('', $couple[0]);
        $this->assertNotSame('', $couple[2]);
    }

    public function test_a_rejected_save_leaves_staged_rows_and_files_intact(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $staged = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/host/events/{$event->id}/media", [
                'slot' => StagedMedia::SLOT_GALLERY,
                'file' => UploadedFile::fake()->image('party.jpg', 400, 300),
            ])->json();

        $row = StagedMedia::query()->findOrFail($staged['id']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson(
                "/api/v1/host/events/{$event->id}/design",
                $this->designPayload($event, [
                    'staged_media' => [$staged['id']],
                    'theme_palette' => 'not-a-real-palette',
                ])
            )
            ->assertUnprocessable();

        $this->assertNotNull(StagedMedia::query()->find($staged['id']));
        Storage::disk('public')->assertExists($row->path);
    }

    public function test_needs_template_and_ticketed_guards(): void
    {
        $user = User::factory()->create();
        $noTemplate = Event::factory()->for($user)->create(['invitation_template_id' => null]);
        $ticketed = Event::factory()->for($user)->ticketed()->create();

        $token = 'Bearer '.$this->tokenFor($user);

        $this->withHeader('Authorization', $token)
            ->patchJson("/api/v1/host/events/{$noTemplate->id}/design", [])
            ->assertStatus(422)->assertJsonPath('reason', 'needs_template');

        $this->withHeader('Authorization', $token)
            ->patchJson("/api/v1/host/events/{$ticketed->id}/design", [])
            ->assertStatus(422)->assertJsonPath('reason', 'is_ticketed');
    }
}
