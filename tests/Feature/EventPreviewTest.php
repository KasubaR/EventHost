<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\StagedMedia;
use App\Models\User;
use App\Support\InvitationMediaUrl;
use App\Support\InvitationPalettes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function template(): InvitationTemplate
    {
        return InvitationTemplate::query()->where('is_active', true)->firstOrFail();
    }

    public function test_owner_can_preview_an_unpublished_draft(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'is_published' => false,
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertOk();
        $response->assertSee('Preview — this is exactly how your invitation looks to guests.', escape: false);
        $response->assertSee($event->name, escape: false);
    }

    public function test_owner_can_preview_a_published_private_event(): void
    {
        // /e/{slug} also renders for a private event once published (it's just
        // never listed anywhere) — this preview page differs in that it ignores
        // is_published/invitation_paused/cancelled entirely, so it's still the
        // only place a host can see a draft, paused, or cancelled event.
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'is_published' => true,
            'is_public' => false,
            'invitation_template_id' => $this->template()->id,
        ]);

        $publicResponse = $this->actingAs($user)->get(route('events.public', $event->slug));
        $publicResponse->assertOk();
        $publicResponse->assertDontSee('This host marked this event as private in settings.');

        $previewResponse = $this->actingAs($user)->get(route('events.preview', $event));
        $previewResponse->assertOk();
        $previewResponse->assertSee('This page is host-only', escape: false);
        $previewResponse->assertDontSee('This host marked this event as private in settings.');
    }

    public function test_other_users_cannot_preview_the_event(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->actingAs($stranger)->get(route('events.preview', $event));

        $response->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $event = Event::factory()->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->get(route('events.preview', $event));

        $response->assertRedirect(route('login'));
    }

    public function test_event_without_a_chosen_layout_redirects_to_choose_template(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertRedirect(route('events.choose-template', $event));
    }

    public function test_preview_does_not_count_as_a_public_invitation_view(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $this->actingAs($user)->get(route('events.preview', $event));

        $this->assertSame(0, $event->fresh()->invitation_views_count);
    }

    public function test_show_page_links_to_the_preview_once_a_layout_is_chosen(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.show', $event));

        $response->assertOk();
        $response->assertSee(route('events.preview', $event), escape: false);
    }

    public function test_show_page_hides_the_preview_link_before_a_layout_is_chosen(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $response = $this->actingAs($user)->get(route('events.show', $event));

        $response->assertOk();
        $response->assertDontSee(route('events.preview', $event), escape: false);
    }

    public function test_preview_opened_directly_links_back_to_edit(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertOk();
        $response->assertSee('Back to edit', escape: false);
        $response->assertSee(route('events.edit', $event), escape: false);
    }

    public function test_preview_opened_from_the_show_page_links_back_to_it(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $this->template()->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.preview', ['event' => $event, 'from' => 'show']));

        $response->assertOk();
        $response->assertSee('Back to event', escape: false);
        $response->assertSee(route('events.show', $event), escape: false);
        $response->assertDontSee('Back to edit', escape: false);
    }

    private function eventWithTemplate(User $user, string $slug): Event
    {
        $tpl = InvitationTemplate::query()->where('slug', $slug)->firstOrFail();

        return Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);
    }

    public function test_palette_query_recolours_the_preview_without_saving(): void
    {
        $user = User::factory()->proPlus()->create();
        $event = $this->eventWithTemplate($user, 'slate-minimal');
        $palette = InvitationPalettes::get('sage-ivory');
        $this->assertNotSame($palette['background'], $event->invitationTemplate->default_theme['background']);

        $response = $this->actingAs($user)->get(route('events.preview', ['event' => $event, 'palette' => 'sage-ivory']));

        $response->assertOk();
        $response->assertSee('--evt-primary: '.$palette['primary'], escape: false);
        $response->assertSee('--evt-accent: '.$palette['accent'], escape: false);
        $response->assertSee('--evt-background: '.$palette['background'], escape: false);
        $response->assertSee('palette — not saved', escape: false);
        $response->assertSee('Show saved colours', escape: false);
        $response->assertDontSee('this is exactly how your invitation looks to guests', escape: false);

        $this->assertNull($event->fresh()->invitation_customization);
    }

    public function test_host_below_pro_plus_can_preview_a_palette(): void
    {
        $user = User::factory()->create();
        $event = $this->eventWithTemplate($user, 'slate-minimal');
        $palette = InvitationPalettes::get('navy-coral');

        $response = $this->actingAs($user)->get(route('events.preview', ['event' => $event, 'palette' => 'navy-coral']));

        $response->assertOk();
        $response->assertSee('--evt-background: '.$palette['background'], escape: false);
        $response->assertSee('palette — not saved', escape: false);
    }

    public function test_unknown_palette_key_falls_back_to_saved_colours(): void
    {
        $user = User::factory()->proPlus()->create();
        $event = $this->eventWithTemplate($user, 'slate-minimal');
        $default = $event->invitationTemplate->default_theme;

        $response = $this->actingAs($user)->get(route('events.preview', ['event' => $event, 'palette' => 'not-a-palette']));

        $response->assertOk();
        $response->assertSee('--evt-background: '.$default['background'], escape: false);
        $response->assertDontSee('palette — not saved', escape: false);
    }

    public function test_palette_of_the_other_mode_is_ignored(): void
    {
        $user = User::factory()->proPlus()->create();
        $event = $this->eventWithTemplate($user, 'slate-minimal');
        $default = $event->invitationTemplate->default_theme;

        $response = $this->actingAs($user)->get(route('events.preview', ['event' => $event, 'palette' => 'noir-gold']));

        $response->assertOk();
        $response->assertSee('--evt-background: '.$default['background'], escape: false);
        $response->assertDontSee('--evt-background: '.InvitationPalettes::get('noir-gold')['background'], escape: false);
        $response->assertDontSee('palette — not saved', escape: false);
    }

    public function test_beauty_for_ashes_ignores_the_palette_query(): void
    {
        $user = User::factory()->proPlus()->create();
        $event = $this->eventWithTemplate($user, 'beauty-for-ashes');
        $key = InvitationPalettes::defaultKeyForMode(
            InvitationPalettes::modeForBackground($event->invitationTemplate->default_theme['background'])
        );

        $response = $this->actingAs($user)->get(route('events.preview', ['event' => $event, 'palette' => $key]));

        $response->assertOk();
        $response->assertDontSee('palette — not saved', escape: false);
    }

    public function test_other_users_cannot_preview_a_palette(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->eventWithTemplate($owner, 'slate-minimal');

        $this->actingAs($stranger)
            ->get(route('events.preview', ['event' => $event, 'palette' => 'sage-ivory']))
            ->assertForbidden();
    }

    private function stagedRow(Event $event, User $user, string $slot, string $path): StagedMedia
    {
        Storage::disk('public')->put($path, 'binary');

        return StagedMedia::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'slot' => $slot,
            'path' => $path,
            'original_name' => basename($path),
            'bytes' => 6,
        ]);
    }

    public function test_staged_gallery_upload_appears_in_preview_before_saving(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $event = $this->eventWithTemplate($user, 'wedding-invitation-2');
        $path = $this->stagedRow($event, $user, StagedMedia::SLOT_GALLERY, 'invitation-gallery/'.$event->id.'/gal_src_new.jpg')->path;

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertOk();
        $response->assertSee(InvitationMediaUrl::resolve($path), escape: false);
        // Never persisted — this is a render-only overlay.
        $this->assertNull($event->fresh()->invitation_customization);
    }

    public function test_staged_hero_portrait_appears_in_preview_before_saving(): void
    {
        Storage::fake('public');
        $user = User::factory()->pro()->create();
        $event = $this->eventWithTemplate($user, 'graduation-template-2-botanical-blush');
        $path = $this->stagedRow($event, $user, StagedMedia::SLOT_HERO_PORTRAIT, 'invitation-hero/'.$event->id.'/hero_src_new.jpg')->path;

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertOk();
        $response->assertSee(InvitationMediaUrl::resolve($path), escape: false);
    }

    public function test_staged_cover_image_appears_in_preview_before_saving(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $event = $this->eventWithTemplate($user, 'wedding-invitation-2');
        $path = $this->stagedRow($event, $user, StagedMedia::SLOT_COVER, 'events/event_new.webp')->path;

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertOk();
        $response->assertSee(asset('storage/'.$path), escape: false);
        $this->assertNull($event->fresh()->cover_image);
    }

    public function test_staged_speaker_slot_fills_the_right_beauty_for_ashes_position(): void
    {
        Storage::fake('public');
        $user = User::factory()->proPlus()->create();
        $event = $this->eventWithTemplate($user, 'beauty-for-ashes');
        $path = $this->stagedRow($event, $user, StagedMedia::speakerSlot(2), 'invitation-couple/'.$event->id.'/couple_src_new.jpg')->path;

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertOk();
        $response->assertSee(InvitationMediaUrl::resolve($path), escape: false);
    }

    public function test_staged_media_older_than_the_ttl_is_not_shown_in_preview(): void
    {
        Storage::fake('public');
        config(['invitations.staged_media_ttl_minutes' => 60]);
        $user = User::factory()->create();
        $event = $this->eventWithTemplate($user, 'wedding-invitation-2');
        $row = $this->stagedRow($event, $user, StagedMedia::SLOT_GALLERY, 'invitation-gallery/'.$event->id.'/gal_src_stale.jpg');
        $row->forceFill(['created_at' => now()->subMinutes(120)])->save();

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertOk();
        $response->assertDontSee(InvitationMediaUrl::resolve($row->path), escape: false);
    }

    public function test_another_users_staged_media_on_the_same_event_does_not_leak_into_preview(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->eventWithTemplate($owner, 'wedding-invitation-2');
        $path = $this->stagedRow($event, $stranger, StagedMedia::SLOT_GALLERY, 'invitation-gallery/'.$event->id.'/gal_src_stray.jpg')->path;

        $response = $this->actingAs($owner)->get(route('events.preview', $event));

        $response->assertOk();
        $response->assertDontSee(InvitationMediaUrl::resolve($path), escape: false);
    }
}
