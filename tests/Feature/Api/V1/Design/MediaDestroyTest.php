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

class MediaDestroyTest extends TestCase
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

    public function test_unstaging_deletes_the_row_and_the_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = $this->eventFor($user);
        $token = 'Bearer '.$this->tokenFor($user);

        $staged = $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/host/events/{$event->id}/media", [
                'slot' => StagedMedia::SLOT_GALLERY,
                'file' => UploadedFile::fake()->image('party.jpg', 400, 300),
            ])->json();

        $row = StagedMedia::query()->findOrFail($staged['id']);

        $this->withHeader('Authorization', $token)
            ->deleteJson("/api/v1/host/events/{$event->id}/media/{$staged['id']}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertNull(StagedMedia::query()->find($staged['id']));
        Storage::disk('public')->assertMissing($row->path);
    }

    public function test_unstaging_an_already_gone_id_is_idempotent(): void
    {
        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson("/api/v1/host/events/{$event->id}/media/999999")
            ->assertOk()
            ->assertJsonPath('deleted', false);
    }

    public function test_non_owner_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->eventFor($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->deleteJson("/api/v1/host/events/{$event->id}/media/1")
            ->assertForbidden();
    }
}
