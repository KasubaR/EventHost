<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTierInvitationTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_invitation_renders_event_invite_layout(): void
    {
        $owner = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'event-invite')->firstOrFail();

        $event = Event::factory()->for($owner)->create([
            'invitation_template_id' => $tpl->id,
            'is_published' => true,
            'name' => "Mukuba's",
            'event_type' => 'birthday',
            'invitation_customization' => [
                'schema_version' => 2,
                'content' => [
                    'ei_color_theme' => 'Denim and Brown',
                    'ei_guest_speaker' => 'Lucy Mulenga',
                    'ei_mc' => 'Rabecca and Natasha',
                ],
            ],
        ]);

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertSee('evt-layout-event-invite', escape: false);
        $response->assertSee('ei-card', escape: false);
        $response->assertSee('Lucy Mulenga', escape: false);
    }

    public function test_base_user_cannot_choose_botanical_graduation_template(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'graduation-template-2-botanical-blush')->firstOrFail();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $response = $this->actingAs($user)->patch(route('events.choose-template.update', $event), [
            'invitation_template_id' => (string) $tpl->id,
        ]);

        $response->assertSessionHasErrors(['invitation_template_id']);
        $this->assertNull($event->fresh()->invitation_template_id);
    }

    public function test_pro_user_can_choose_botanical_graduation_template(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'graduation-template-2-botanical-blush')->firstOrFail();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $response = $this->actingAs($user)->patch(route('events.choose-template.update', $event), [
            'invitation_template_id' => (string) $tpl->id,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame($tpl->id, $event->fresh()->invitation_template_id);
    }

    public function test_public_invitation_renders_botanical_graduation_layout(): void
    {
        $owner = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'graduation-template-2-botanical-blush')->firstOrFail();

        $event = Event::factory()->for($owner)->create([
            'invitation_template_id' => $tpl->id,
            'is_published' => true,
            'name' => 'River Academic Celebration',
        ]);

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertSee('evt-layout-botanical-graduation', escape: false);
        $response->assertSee('evt-bg-nav-strip', escape: false);
        $response->assertSee('hero-left', escape: false);
    }

    public function test_base_user_cannot_choose_beauty_for_ashes_template(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'beauty-for-ashes')->firstOrFail();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $response = $this->actingAs($user)->patch(route('events.choose-template.update', $event), [
            'invitation_template_id' => (string) $tpl->id,
        ]);

        $response->assertSessionHasErrors(['invitation_template_id']);
        $this->assertNull($event->fresh()->invitation_template_id);
    }

    public function test_pro_user_can_choose_beauty_for_ashes_template(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'beauty-for-ashes')->firstOrFail();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $response = $this->actingAs($user)->patch(route('events.choose-template.update', $event), [
            'invitation_template_id' => (string) $tpl->id,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame($tpl->id, $event->fresh()->invitation_template_id);
    }

    public function test_public_invitation_renders_beauty_for_ashes_layout(): void
    {
        $owner = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'beauty-for-ashes')->firstOrFail();

        $event = Event::factory()->for($owner)->create([
            'invitation_template_id' => $tpl->id,
            'is_published' => true,
            'name' => 'Beauty For Ashes',
            'event_type' => 'church',
        ]);

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertSee('evt-layout-beauty-for-ashes', escape: false);
        $response->assertSee('bfa-hero', escape: false);
        $response->assertSee('bfa-hero-countdown', escape: false);
    }
}
