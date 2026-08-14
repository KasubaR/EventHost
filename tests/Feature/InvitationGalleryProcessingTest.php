<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvitationGalleryProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_upload_is_stored_as_webp_after_queue_job(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);

        $order = collect($tpl->default_sections)->pluck('type')->values()->all();
        $visibility = [];
        foreach ($order as $type) {
            $visibility[$type] = '1';
        }

        $file = UploadedFile::fake()->image('upload.jpg', 120, 120);

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), [
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
            'gallery_images' => [$file],
            'rsvp_form' => [
                'message' => ['visible' => '1', 'label' => 'Message to host'],
                'meal_preference' => ['visible' => '1', 'label' => 'Meal preference'],
                'transportation_note' => ['visible' => '1', 'label' => 'Transportation notes'],
                'song_request' => ['visible' => '1', 'label' => 'Song request'],
            ],
        ])->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $paths = $event->invitation_customization['media']['gallery'] ?? [];

        $this->assertCount(1, $paths);
        $this->assertMatchesRegularExpression('/\.webp$/', $paths[0]);
        Storage::disk('public')->assertExists($paths[0]);

        $srcLeft = collect(Storage::disk('public')->allFiles('invitation-gallery/'.$event->id))
            ->filter(fn (string $p): bool => str_contains(basename($p), 'gal_src_'));

        $this->assertTrue($srcLeft->isEmpty(), 'Original upload should be removed after WebP conversion.');

        $media = $event->invitation_customization['media'];
        $this->assertArrayHasKey('hero_portrait', $media);
        $this->assertNull($media['hero_portrait']);
        $this->assertSame([], $media['couple_photos']);
    }

    public function test_botanical_invitation_hero_portrait_converts_to_webp(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'graduation-template-2-botanical-blush')->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);

        $order = collect($tpl->default_sections)->pluck('type')->values()->all();
        $visibility = [];
        foreach ($order as $type) {
            $visibility[$type] = '1';
        }

        $file = UploadedFile::fake()->image('hero.jpg', 400, 500);

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), [
            'theme_palette' => 'slate-sky',
            'font_heading_key' => 'inter',
            'font_body_key' => 'inter',
            'animation_subtle' => '0',
            'countdown_enabled' => '1',
            'section_order' => $order,
            'section_visible' => $visibility,
            'clear_video' => '0',
            'clear_audio' => '0',
            'clear_hero_portrait' => '0',
            'content_story' => '',
            'schedule_items' => [],
            'invitation_hero_portrait' => $file,
            'rsvp_form' => [
                'message' => ['visible' => '1', 'label' => 'Message to host'],
                'meal_preference' => ['visible' => '1', 'label' => 'Meal preference'],
                'transportation_note' => ['visible' => '1', 'label' => 'Transportation notes'],
                'song_request' => ['visible' => '1', 'label' => 'Song request'],
            ],
        ])->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $hero = $event->invitation_customization['media']['hero_portrait'] ?? null;

        $this->assertIsString($hero);
        $this->assertMatchesRegularExpression('#\.webp$#', $hero);
        Storage::disk('public')->assertExists($hero);

        $srcLeft = collect(Storage::disk('public')->allFiles('invitation-hero/'.$event->id))
            ->filter(fn (string $p): bool => str_contains(basename($p), 'hero_src_'));

        $this->assertTrue($srcLeft->isEmpty(), 'Original hero upload should be removed after WebP conversion.');
    }

    public function test_standard_layout_rejects_invitation_hero_portrait_upload(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);

        $order = collect($tpl->default_sections)->pluck('type')->values()->all();
        $visibility = [];
        foreach ($order as $type) {
            $visibility[$type] = '1';
        }

        $file = UploadedFile::fake()->image('hero.jpg', 80, 80);

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), [
            'theme_palette' => 'slate-sky',
            'font_heading_key' => 'inter',
            'font_body_key' => 'inter',
            'animation_subtle' => '0',
            'countdown_enabled' => '1',
            'section_order' => $order,
            'section_visible' => $visibility,
            'clear_video' => '0',
            'clear_audio' => '0',
            'clear_hero_portrait' => '0',
            'content_story' => '',
            'schedule_items' => [],
            'invitation_hero_portrait' => $file,
        ])->assertSessionHasErrors('invitation_hero_portrait');
    }

    public function test_botanical_couple_photos_convert_to_webp(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'graduation-template-2-botanical-blush')->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);

        $order = collect($tpl->default_sections)->pluck('type')->values()->all();
        $visibility = [];
        foreach ($order as $type) {
            $visibility[$type] = '1';
        }

        $one = UploadedFile::fake()->image('a.jpg', 200, 260);
        $two = UploadedFile::fake()->image('b.jpg', 200, 260);

        $this->actingAs($user)->patch(route('events.invitation-design.update', $event), [
            'theme_palette' => 'slate-sky',
            'font_heading_key' => 'inter',
            'font_body_key' => 'inter',
            'animation_subtle' => '0',
            'countdown_enabled' => '1',
            'section_order' => $order,
            'section_visible' => $visibility,
            'clear_video' => '0',
            'clear_audio' => '0',
            'clear_hero_portrait' => '0',
            'content_story' => '',
            'schedule_items' => [],
            'couple_photos' => [$one, $two],
            'rsvp_form' => [
                'message' => ['visible' => '1', 'label' => 'Message to host'],
                'meal_preference' => ['visible' => '1', 'label' => 'Meal preference'],
                'transportation_note' => ['visible' => '1', 'label' => 'Transportation notes'],
                'song_request' => ['visible' => '1', 'label' => 'Song request'],
            ],
        ])->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $paths = $event->invitation_customization['media']['couple_photos'] ?? [];

        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->assertMatchesRegularExpression('#\.webp$#', $path);
            Storage::disk('public')->assertExists($path);
        }

        $srcLeft = collect(Storage::disk('public')->allFiles('invitation-couple/'.$event->id))
            ->filter(fn (string $p): bool => str_contains(basename($p), 'couple_src_'));

        $this->assertTrue($srcLeft->isEmpty(), 'Original couple uploads should be removed after WebP conversion.');
    }
}
