<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AudioReport;
use App\Models\Event;
use App\Notifications\AudioReportNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AudioReportTest extends TestCase
{
    use RefreshDatabase;

    private function eventWithAudio(string $path = 'invitations/audio/song.mp3'): Event
    {
        Storage::fake('public');
        Storage::disk('public')->put($path, 'x');

        $event = Event::factory()->create();
        $event->invitation_customization = ['effects' => ['audio_track' => $path]];
        $event->save();

        return $event;
    }

    private function admin(string $role = 'admin'): Admin
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = Admin::factory()->create();
        $admin->assignRole($role);

        return $admin;
    }

    public function test_anyone_can_report_music_and_support_is_emailed(): void
    {
        Notification::fake();
        $event = $this->eventWithAudio();

        $this->post(route('audio-report.store', $event), [
            'name' => 'Rights Holder',
            'email' => 'owner@example.com',
            'details' => 'This is my song and I did not license it.',
        ])->assertRedirect(route('audio-report.show', $event));

        $this->assertDatabaseHas('audio_reports', [
            'event_id' => $event->id,
            'status' => AudioReport::OPEN,
            'audio_path' => 'invitations/audio/song.mp3',
        ]);
        Notification::assertSentOnDemand(AudioReportNotification::class);
    }

    public function test_an_event_without_music_cannot_be_reported(): void
    {
        $event = Event::factory()->create();

        $this->get(route('audio-report.show', $event))->assertNotFound();
        $this->post(route('audio-report.store', $event), [
            'name' => 'A', 'email' => 'a@example.com', 'details' => 'long enough details',
        ])->assertNotFound();
    }

    public function test_admin_removing_a_reported_track_deletes_the_file_and_closes_the_report(): void
    {
        $event = $this->eventWithAudio();
        $report = AudioReport::query()->create([
            'event_id' => $event->id, 'event_name' => $event->name, 'audio_path' => 'invitations/audio/song.mp3',
            'reporter_name' => 'R', 'reporter_email' => 'r@example.com', 'details' => 'details here please',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.audio-reports.remove', $report))
            ->assertRedirect();

        $this->assertNull($event->fresh()->invitation_customization['effects']['audio_track']);
        Storage::disk('public')->assertMissing('invitations/audio/song.mp3');
        $this->assertSame(AudioReport::REMOVED, $report->fresh()->status);
    }

    public function test_admin_can_remove_music_straight_from_the_event_page(): void
    {
        $event = $this->eventWithAudio();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.events.remove-audio', $event))
            ->assertRedirect();

        $this->assertNull($event->fresh()->invitation_customization['effects']['audio_track']);
        Storage::disk('public')->assertMissing('invitations/audio/song.mp3');
    }

    public function test_dismissing_leaves_the_music_in_place(): void
    {
        $event = $this->eventWithAudio();
        $report = AudioReport::query()->create([
            'event_id' => $event->id, 'event_name' => $event->name, 'audio_path' => 'invitations/audio/song.mp3',
            'reporter_name' => 'R', 'reporter_email' => 'r@example.com', 'details' => 'details here please',
        ]);

        $this->actingAs($this->admin(), 'admin')->post(route('admin.audio-reports.dismiss', $report));

        $this->assertSame('invitations/audio/song.mp3', $event->fresh()->invitation_customization['effects']['audio_track']);
        $this->assertSame(AudioReport::DISMISSED, $report->fresh()->status);
    }

    public function test_guests_cannot_reach_the_admin_queue(): void
    {
        $this->get(route('admin.audio-reports.index'))->assertRedirect();
    }

    public function test_the_queue_page_renders(): void
    {
        $event = $this->eventWithAudio();
        AudioReport::query()->create([
            'event_id' => $event->id, 'event_name' => $event->name, 'reporter_name' => 'R',
            'reporter_email' => 'r@example.com', 'details' => 'details here please',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.audio-reports.index'))
            ->assertOk()
            ->assertSee($event->name);
    }

    public function test_terms_state_the_audio_rules(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertSee('Music and other audio.', false)
            ->assertSee('Report this music', false);
    }
}
