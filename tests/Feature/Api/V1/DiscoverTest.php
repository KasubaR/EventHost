<?php

namespace Tests\Feature\Api\V1;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoverTest extends TestCase
{
    use RefreshDatabase;

    private function publicEvent(array $overrides = []): Event
    {
        return Event::factory()->published()->create(array_merge([
            'is_public' => true,
            'event_date' => now()->addWeek()->format('Y-m-d'),
        ], $overrides));
    }

    public function test_only_publicly_listed_upcoming_events_are_returned(): void
    {
        $visible = $this->publicEvent();
        $private = $this->publicEvent(['is_public' => false]);
        $unpublished = Event::factory()->create(['is_public' => true, 'event_date' => now()->addWeek()->format('Y-m-d')]);
        $cancelled = $this->publicEvent(['cancelled_at' => now()]);
        $paused = $this->publicEvent(['invitation_paused_at' => now()]);
        $past = $this->publicEvent(['event_date' => now()->subWeek()->format('Y-m-d')]);

        $response = $this->getJson('/api/v1/discover');

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug');

        $this->assertTrue($slugs->contains($visible->slug));
        $this->assertFalse($slugs->contains($private->slug));
        $this->assertFalse($slugs->contains($unpublished->slug));
        $this->assertFalse($slugs->contains($cancelled->slug));
        $this->assertFalse($slugs->contains($paused->slug));
        $this->assertFalse($slugs->contains($past->slug));
    }

    public function test_results_are_ordered_by_event_date_then_time(): void
    {
        $later = $this->publicEvent(['event_date' => now()->addMonth()->format('Y-m-d')]);
        $sooner = $this->publicEvent(['event_date' => now()->addDays(2)->format('Y-m-d')]);

        $slugs = collect($this->getJson('/api/v1/discover')->json('data'))->pluck('slug')->values();

        $this->assertSame($sooner->slug, $slugs->first());
        $this->assertSame($later->slug, $slugs->last());
    }

    public function test_results_are_paginated_at_twelve_per_page(): void
    {
        Event::factory()->count(13)->published()->create([
            'is_public' => true,
            'event_date' => now()->addWeek()->format('Y-m-d'),
        ]);

        $response = $this->getJson('/api/v1/discover');

        $response->assertOk();
        $this->assertCount(12, $response->json('data'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function test_no_authentication_is_required(): void
    {
        $this->publicEvent();

        $this->getJson('/api/v1/discover')->assertOk();
    }
}
