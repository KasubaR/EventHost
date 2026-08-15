<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\StagedMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A save carrying staged ids must land the same customization as the same save
 * carrying binaries — for every layout that has portrait slots.
 *
 * The couple-photo branch is where they can diverge: Beauty for Ashes addresses
 * four portraits positionally (speaker:0…3, where a file replaces one slot), while
 * every other layout takes a batch that appends.
 */
class StagedMediaPerLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function eventFor(User $user, string $slug): Event
    {
        $tpl = InvitationTemplate::query()->where('slug', $slug)->firstOrFail();

        return Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Event $event, array $overrides = []): array
    {
        $tpl = InvitationTemplate::findOrFail($event->invitation_template_id);
        $order = collect($tpl->default_sections)->pluck('type')->values()->all();
        $visibility = [];
        foreach ($order as $type) {
            $visibility[$type] = '1';
        }

        $base = [
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
        ];

        // theme_palette is deliberately omitted: it's a Pro+ feature and these
        // tests use base-tier users, so it's optional and the save keeps the
        // template's default theme without it — same as a real base-tier submit.

        return array_merge($base, $overrides);
    }

    private function stage(User $user, Event $event, string $slot, string $name): int
    {
        return $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => $slot,
            'file' => UploadedFile::fake()->image($name, 300, 400),
        ])->assertCreated()->json('id');
    }

    public function test_botanical_hero_portrait_via_staging_matches_a_direct_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $staged = $this->eventFor($user, 'graduation-template-2-botanical-blush');
        $direct = $this->eventFor($user, 'graduation-template-2-botanical-blush');

        $id = $this->stage($user, $staged, StagedMedia::SLOT_HERO_PORTRAIT, 'hero.jpg');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $staged), $this->payload($staged, ['staged_media' => [$id]]))
            ->assertSessionHas('status', 'invitation-design-saved');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $direct), $this->payload($direct, [
                'invitation_hero_portrait' => UploadedFile::fake()->image('hero.jpg', 300, 400),
            ]))
            ->assertSessionHas('status', 'invitation-design-saved');

        $staged->refresh();
        $direct->refresh();

        $stagedHero = $staged->invitation_customization['media']['hero_portrait'];
        $directHero = $direct->invitation_customization['media']['hero_portrait'];

        $this->assertNotNull($stagedHero);
        $this->assertNotNull($directHero);
        // Same directory shape and same extension — only the entropy differs.
        $this->assertMatchesRegularExpression('#^invitation-hero/'.$staged->id.'/.+\.webp$#', $stagedHero);
        $this->assertMatchesRegularExpression('#^invitation-hero/'.$direct->id.'/.+\.webp$#', $directHero);
        Storage::disk('public')->assertExists($stagedHero);
    }

    public function test_wedding_couple_grid_appends_staged_portraits(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $event = $this->eventFor($user, 'wedding-invitation');

        $ids = [
            $this->stage($user, $event, StagedMedia::SLOT_COUPLE, 'left.jpg'),
            $this->stage($user, $event, StagedMedia::SLOT_COUPLE, 'centre.jpg'),
            $this->stage($user, $event, StagedMedia::SLOT_COUPLE, 'right.jpg'),
        ];

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->payload($event, ['staged_media' => $ids]))
            ->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $couple = $event->invitation_customization['media']['couple_photos'];

        $this->assertCount(3, $couple);
        foreach ($couple as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_wedding_couple_grid_rejects_a_fourth_staged_portrait(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $event = $this->eventFor($user, 'wedding-invitation');

        for ($i = 0; $i < 3; $i++) {
            $this->stage($user, $event, StagedMedia::SLOT_COUPLE, "p{$i}.jpg");
        }

        // maxCouplePhotoSlots() is 3 for this layout, so staging stops here.
        $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_COUPLE,
            'file' => UploadedFile::fake()->image('fourth.jpg', 300, 400),
        ])->assertStatus(422);
    }

    public function test_beauty_for_ashes_staged_speaker_photo_fills_its_own_slot(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $event = $this->eventFor($user, 'beauty-for-ashes');

        // Slot 2 only — slots 0, 1 and 3 must stay empty.
        $id = $this->stage($user, $event, StagedMedia::speakerSlot(2), 'speaker3.jpg');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->payload($event, ['staged_media' => [$id]]))
            ->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $couple = $event->invitation_customization['media']['couple_photos'];

        $this->assertCount(4, $couple);
        $this->assertSame('', $couple[0]);
        $this->assertSame('', $couple[1]);
        $this->assertNotSame('', $couple[2]);
        $this->assertSame('', $couple[3]);
        Storage::disk('public')->assertExists($couple[2]);
    }

    public function test_beauty_for_ashes_replaces_a_full_slot_without_tripping_the_cap(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $event = $this->eventFor($user, 'beauty-for-ashes');

        // All four slots already occupied.
        $existing = [];
        for ($i = 0; $i < 4; $i++) {
            $path = 'invitation-couple/'.$event->id.'/couple_src_'.$i.'.jpg';
            Storage::disk('public')->put($path, 'x');
            $existing[] = $path;
        }
        $event->invitation_customization = ['media' => ['couple_photos' => $existing]];
        $event->save();

        $id = $this->stage($user, $event, StagedMedia::speakerSlot(1), 'new-speaker.jpg');

        $this->actingAs($user)
            ->patch(route('events.invitation-design.update', $event), $this->payload($event, ['staged_media' => [$id]]))
            ->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $couple = $event->invitation_customization['media']['couple_photos'];

        $this->assertCount(4, $couple);
        $this->assertSame($existing[0], $couple[0]);
        $this->assertNotSame($existing[1], $couple[1]);
        $this->assertSame($existing[2], $couple[2]);
        $this->assertSame($existing[3], $couple[3]);
        // The portrait it replaced is gone.
        Storage::disk('public')->assertMissing($existing[1]);
    }

    public function test_numbered_speaker_slots_are_rejected_on_a_batch_layout(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $event = $this->eventFor($user, 'wedding-invitation');

        $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::speakerSlot(0),
            'file' => UploadedFile::fake()->image('nope.jpg', 300, 400),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_the_batch_slot_is_rejected_on_beauty_for_ashes(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $event = $this->eventFor($user, 'beauty-for-ashes');

        $this->actingAs($user)->postJson(route('events.media.stage', $event), [
            'slot' => StagedMedia::SLOT_COUPLE,
            'file' => UploadedFile::fake()->image('nope.jpg', 300, 400),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }
}
