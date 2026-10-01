<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvitationCustomizationInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    private function designPayload(Event $event, InvitationTemplate $tpl): array
    {
        $order = collect($tpl->default_sections)->pluck('type')->values()->all();
        $visibility = [];
        foreach ($order as $type) {
            $visibility[$type] = '1';
        }

        return [
            'theme_palette' => 'slate-sky',
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
            ],
        ];
    }

    public function test_gallery_total_byte_cap_blocks_oversized_batches(): void
    {
        Storage::fake('public');

        Config::set('invitations.gallery_max_total_bytes', 8000);

        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);

        $existingPath = 'invitation-gallery/'.$event->id.'/existing.webp';
        Storage::disk('public')->put($existingPath, str_repeat('x', 5000));

        $sections = collect($tpl->default_sections)->map(fn ($row) => [
            'type' => $row['type'],
            'visible' => true,
        ])->values()->all();

        $event->invitation_customization = [
            'schema_version' => 2,
            'theme' => [
                'primary' => '#101010',
                'accent' => '#0ea5e9',
                'background' => '#fefefe',
                'font_heading_key' => 'inter',
                'font_body_key' => 'inter',
            ],
            'sections' => $sections,
            'content' => [
                'story' => '',
                'schedule' => [],
            ],
            'media' => [
                'gallery' => [$existingPath],
                'hero_portrait' => null,
                'couple_photos' => [],
            ],
            'effects' => [
                'animation_subtle' => false,
                'countdown_enabled' => true,
                'video_background' => null,
                'audio_track' => null,
            ],
        ];
        $event->save();

        $payload = $this->designPayload($event, $tpl);
        $payload['gallery_images'] = [
            UploadedFile::fake()->create('new.jpg', 4),
        ];

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), $payload)
            ->assertSessionHasErrors('gallery_images');
    }

    public function test_song_title_and_artist_are_saved_with_the_track_and_dropped_with_it(): void
    {
        Storage::fake('public');

        $user = User::factory()->proPlus()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);
        $payload = $this->designPayload($event, $tpl);

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), array_merge($payload, [
            'audio_track' => UploadedFile::fake()->create('song.mp3', 20, 'audio/mpeg'),
            'audio_title' => '  Heaven Baby ',
            'audio_artist' => 'Ayra Starr',
        ]))->assertSessionDoesntHaveErrors();

        $effects = $event->fresh()->invitation_customization['effects'];
        $this->assertNotNull($effects['audio_track']);
        $this->assertSame('Heaven Baby', $effects['audio_title']);
        $this->assertSame('Ayra Starr', $effects['audio_artist']);

        // Editing only the details keeps the file.
        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), array_merge($payload, [
            'audio_title' => 'Heaven Baby (feat. ZAYN)',
            'audio_artist' => 'Ayra Starr',
        ]))->assertSessionDoesntHaveErrors();
        $kept = $event->fresh()->invitation_customization['effects'];
        $this->assertSame($effects['audio_track'], $kept['audio_track']);
        $this->assertSame('Heaven Baby (feat. ZAYN)', $kept['audio_title']);

        // Removing the track takes the details with it, even if the form still sends them.
        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), array_merge($payload, [
            'clear_audio' => '1',
            'audio_title' => 'Heaven Baby',
            'audio_artist' => 'Ayra Starr',
        ]))->assertSessionDoesntHaveErrors();
        $gone = $event->fresh()->invitation_customization['effects'];
        $this->assertNull($gone['audio_track']);
        $this->assertNull($gone['audio_title']);
        $this->assertNull($gone['audio_artist']);
    }

    public function test_previous_customization_snapshot_stored_on_second_save(): void
    {
        Storage::fake('public');

        // Pro+ so the theme_palette changes below actually apply — palette here
        // is just a convenient changing field, not what this test is about.
        $user = User::factory()->proPlus()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);

        $payload = $this->designPayload($event, $tpl);

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), array_merge($payload, [
            'theme_palette' => 'slate-sky',
        ]))->assertSessionDoesntHaveErrors();

        $event->refresh();
        $this->assertSame('slate-sky', $event->invitation_customization['theme']['palette_key']);
        $this->assertNull($event->invitation_customization_previous);

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), array_merge($payload, [
            'theme_palette' => 'magazine-red',
        ]))->assertSessionDoesntHaveErrors();

        $event->refresh();
        $this->assertSame('magazine-red', $event->invitation_customization['theme']['palette_key']);
        $this->assertIsArray($event->invitation_customization_previous);
        $this->assertSame('slate-sky', $event->invitation_customization_previous['theme']['palette_key']);
        $this->assertSame($user->id, $event->invitation_customization_previous_captured_by_user_id);
        $this->assertNotNull($event->invitation_customization_previous_captured_at);
    }

    public function test_invitation_design_rate_limit_returns_429(): void
    {
        Storage::fake('public');

        Config::set('invitations.design_updates_per_minute', 2);

        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);

        $payload = $this->designPayload($event, $tpl);

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), $payload)->assertRedirect();
        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), $payload)->assertRedirect();

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), $payload)->assertStatus(429);
    }
}
