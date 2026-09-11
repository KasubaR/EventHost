<?php

namespace Tests\Feature\Api\V1\Design;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTest extends TestCase
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

    public function test_returns_invitation_fingerprint_token_and_catalog(): void
    {
        $user = User::factory()->create();
        $event = $this->eventFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}/design");

        $response->assertOk()
            ->assertJsonStructure([
                'invitation' => ['theme', 'sections', 'content', 'media', 'effects', 'rsvp_form'],
                'template_fingerprint',
                'customization_token',
                'template' => ['id', 'slug', 'name', 'layout_variant', 'skin'],
                'catalog' => ['palettes', 'palette_locked', 'fonts', 'available_sections', 'max_hero_portrait_slots', 'max_couple_photo_slots', 'gallery_max', 'gallery_max_total_bytes'],
            ]);
    }

    public function test_palette_is_locked_for_a_base_tier_owner(): void
    {
        $base = User::factory()->create(); // Base tier
        $event = $this->eventFor($base);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($base))
            ->getJson("/api/v1/host/events/{$event->id}/design")
            ->assertOk()
            ->assertJsonPath('catalog.palette_locked', true)
            ->assertJsonPath('catalog.palettes', []);
    }

    public function test_palette_is_unlocked_for_a_pro_plus_owner(): void
    {
        $proPlus = User::factory()->proPlus()->create();
        $event = $this->eventFor($proPlus);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($proPlus))
            ->getJson("/api/v1/host/events/{$event->id}/design");

        $response->assertOk()->assertJsonPath('catalog.palette_locked', false);
        $this->assertNotEmpty($response->json('catalog.palettes'));
    }

    public function test_needs_template_reason_when_no_template_chosen(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}/design")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'needs_template');
    }

    public function test_is_ticketed_reason_for_a_ticketed_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}/design")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'is_ticketed');
    }

    public function test_accepted_manager_staff_can_view(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $event = $this->eventFor($owner);
        EventStaff::factory()->for($event)->manager()->create([
            'user_id' => $staffer->id,
            'accepted_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->getJson("/api/v1/host/events/{$event->id}/design")
            ->assertOk();
    }

    public function test_non_owner_non_manager_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->eventFor($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/host/events/{$event->id}/design")
            ->assertForbidden();
    }
}
