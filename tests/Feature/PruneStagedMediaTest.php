<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\StagedMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * invitation:prune-orphaned-files deletes files no event references, past a grace
 * window. Staged uploads sit in exactly those directories without being referenced
 * by any customization, so without the staged_media exemption the pruner would eat
 * photos the user can still see on screen.
 */
class PruneStagedMediaTest extends TestCase
{
    use RefreshDatabase;

    private function stagedRow(Event $event, User $user, string $path): StagedMedia
    {
        Storage::disk('public')->put($path, 'binary');

        return StagedMedia::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'slot' => StagedMedia::SLOT_GALLERY,
            'path' => $path,
            'original_name' => basename($path),
            'bytes' => 6,
        ]);
    }

    public function test_a_live_staged_file_survives_the_orphan_sweep(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $row = $this->stagedRow($event, $user, 'invitation-gallery/'.$event->id.'/gal_src_live.jpg');

        // grace=0 would be clamped to 1; use a window every file is older than.
        $this->travel(2)->hours();

        $this->artisan('invitation:prune-orphaned-files', ['--grace' => 1])
            ->assertExitCode(0);

        $this->assertNotNull(StagedMedia::query()->find($row->id));
        Storage::disk('public')->assertExists($row->path);
    }

    public function test_an_abandoned_staged_upload_is_deleted_after_its_ttl(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $row = $this->stagedRow($event, $user, 'invitation-gallery/'.$event->id.'/gal_src_stale.jpg');

        // Default TTL is 24 h.
        $this->travel(25)->hours();

        $this->artisan('invitation:prune-orphaned-files', ['--grace' => 1])
            ->assertExitCode(0);

        $this->assertNull(StagedMedia::query()->find($row->id));
        Storage::disk('public')->assertMissing($row->path);
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $row = $this->stagedRow($event, $user, 'invitation-gallery/'.$event->id.'/gal_src_stale.jpg');

        $this->travel(25)->hours();

        $this->artisan('invitation:prune-orphaned-files', ['--grace' => 1, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNotNull(StagedMedia::query()->find($row->id));
        Storage::disk('public')->assertExists($row->path);
    }

    public function test_the_staged_ttl_is_overridable(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $row = $this->stagedRow($event, $user, 'invitation-gallery/'.$event->id.'/gal_src_stale.jpg');

        $this->travel(3)->hours();

        $this->artisan('invitation:prune-orphaned-files', ['--grace' => 1, '--staged-ttl' => 60])
            ->assertExitCode(0);

        $this->assertNull(StagedMedia::query()->find($row->id));
    }
}
