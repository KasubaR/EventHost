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
            'theme_primary' => '#101010',
            'theme_accent' => '#0ea5e9',
            'theme_background' => '#fefefe',
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
                'message'             => ['visible' => '1', 'label' => 'Message to host'],
                'meal_preference'     => ['visible' => '1', 'label' => 'Meal preference'],
                'transportation_note' => ['visible' => '1', 'label' => 'Transportation notes'],
                'song_request'        => ['visible' => '1', 'label' => 'Song request'],
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

    public function test_previous_customization_snapshot_stored_on_second_save(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);

        $payload = $this->designPayload($event, $tpl);

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), array_merge($payload, [
            'theme_primary' => '#111111',
        ]))->assertSessionDoesntHaveErrors();

        $event->refresh();
        $this->assertSame('#111111', $event->invitation_customization['theme']['primary']);
        $this->assertNull($event->invitation_customization_previous);

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), array_merge($payload, [
            'theme_primary' => '#222222',
        ]))->assertSessionDoesntHaveErrors();

        $event->refresh();
        $this->assertSame('#222222', $event->invitation_customization['theme']['primary']);
        $this->assertIsArray($event->invitation_customization_previous);
        $this->assertSame('#111111', $event->invitation_customization_previous['theme']['primary']);
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
