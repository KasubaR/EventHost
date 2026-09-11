<?php

namespace Tests\Feature\Api\V1\Events;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChooseTemplateIndexTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_returns_only_active_templates(): void
    {
        // The base TestCase seeds InvitationTemplateSeeder for every test, so the
        // catalog is never empty here — assert the inactive one is excluded and
        // the newly-created active one is present, rather than an exact count.
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $active = InvitationTemplate::factory()->create(['is_active' => true]);
        $inactive = InvitationTemplate::factory()->create(['is_active' => false]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}/choose-template");

        $response->assertOk();
        $ids = collect($response->json('templates'))->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_q_filters_by_name(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $match = InvitationTemplate::factory()->create(['name' => 'Zzyzx Unique Layout Name']);
        InvitationTemplate::factory()->create(['name' => 'Modern Minimal']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}/choose-template?q=Zzyzx");

        $ids = collect($response->json('templates'))->pluck('id')->all();
        $this->assertSame([$match->id], $ids);
    }

    public function test_category_filters_by_category_slug(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $category = InvitationTemplateCategory::create(['slug' => 'weddings', 'name' => 'Weddings', 'sort_order' => 0]);
        $matching = InvitationTemplate::factory()->create();
        $matching->categories()->attach($category->id);
        InvitationTemplate::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}/choose-template?category=weddings");

        $ids = collect($response->json('templates'))->pluck('id')->all();
        $this->assertSame([$matching->id], $ids);
    }

    public function test_ticketed_event_is_rejected(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}/choose-template")
            ->assertStatus(422)
            ->assertJsonPath('error', 'is_ticketed');
    }

    public function test_non_owner_non_manager_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/host/events/{$event->id}/choose-template")
            ->assertForbidden();
    }
}
