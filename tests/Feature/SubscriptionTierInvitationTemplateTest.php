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
}
