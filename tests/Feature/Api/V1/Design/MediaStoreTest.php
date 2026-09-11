<?php

namespace Tests\Feature\Api\V1\Design;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\StagedMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaStoreTest extends TestCase
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

    public function test_staging_an_image_stores_it_and_returns_an_id(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/host/events/{$event->id}/media", [
                'slot' => StagedMedia::SLOT_GALLERY,
                'file' => UploadedFile::fake()->image('party.jpg', 400, 300),
            ]);

        $response->assertCreated()->assertJsonStructure(['id', 'slot', 'url', 'name', 'bytes']);

        $staged = StagedMedia::query()->firstOrFail();
        $this->assertSame($event->id, $staged->event_id);
        $this->assertSame($user->id, $staged->user_id);
        Storage::disk('public')->assertExists($staged->path);
    }

    public function test_single_value_slot_replaces_the_previous_upload(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user, 'graduation-template-2-botanical-blush');
        $token = 'Bearer '.$this->tokenFor($user);

        $first = $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/host/events/{$event->id}/media", [
                'slot' => StagedMedia::SLOT_HERO_PORTRAIT,
                'file' => UploadedFile::fake()->image('one.jpg', 200, 300),
            ])->assertCreated()->json();

        $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/host/events/{$event->id}/media", [
                'slot' => StagedMedia::SLOT_HERO_PORTRAIT,
                'file' => UploadedFile::fake()->image('two.jpg', 200, 300),
            ])->assertCreated();

        $this->assertSame(1, StagedMedia::query()->where('slot', StagedMedia::SLOT_HERO_PORTRAIT)->count());
        $this->assertNull(StagedMedia::query()->find($first['id']));
    }

    public function test_gallery_cap_is_enforced_at_staging_time(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);
        $token = 'Bearer '.$this->tokenFor($user);

        for ($i = 0; $i < 6; $i++) {
            $this->withHeader('Authorization', $token)
                ->postJson("/api/v1/host/events/{$event->id}/media", [
                    'slot' => StagedMedia::SLOT_GALLERY,
                    'file' => UploadedFile::fake()->image("g{$i}.jpg", 80, 80),
                ])->assertCreated();
        }

        $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/host/events/{$event->id}/media", [
                'slot' => StagedMedia::SLOT_GALLERY,
                'file' => UploadedFile::fake()->image('seventh.jpg', 80, 80),
            ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_wrong_owner_is_forbidden(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->eventFor($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/host/events/{$event->id}/media", [
                'slot' => StagedMedia::SLOT_GALLERY,
                'file' => UploadedFile::fake()->image('sneaky.jpg', 80, 80),
            ])->assertForbidden();
    }

    public function test_ticketed_cover_upload_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/host/events/{$event->id}/media", [
                'slot' => StagedMedia::SLOT_COVER,
                'file' => UploadedFile::fake()->image('cover.jpg', 1200, 630),
            ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_layout_incompatible_hero_portrait_slot_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        // slate-minimal has no separate hero portrait slot.
        $event = $this->eventFor($user);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/host/events/{$event->id}/media", [
                'slot' => StagedMedia::SLOT_HERO_PORTRAIT,
                'file' => UploadedFile::fake()->image('hero.jpg', 200, 300),
            ])->assertStatus(422)->assertJsonValidationErrors('file');
    }
}
