<?php

namespace Tests\Feature\Api\V1\TableUpload;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    private function liveGalleryEvent(array $overrides = []): Event
    {
        $owner = User::factory()->pro()->create();

        return Event::factory()->for($owner)->create(array_merge([
            'is_public' => true,
            'is_published' => true,
        ], $overrides));
    }

    public function test_is_live_true_for_a_premium_owner(): void
    {
        $event = $this->liveGalleryEvent();
        $table = EventTable::factory()->for($event)->create();

        $response = $this->getJson(route('api.v1.table.show', ['slug' => $event->slug, 'code' => $table->code]));

        $response->assertOk()
            ->assertJsonPath('is_live', true)
            ->assertJsonPath('table.code', $table->code);
    }

    public function test_is_live_false_for_a_non_premium_owner(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['is_public' => true, 'is_published' => true]);
        $table = EventTable::factory()->for($event)->create();

        $this->getJson(route('api.v1.table.show', ['slug' => $event->slug, 'code' => $table->code]))
            ->assertOk()
            ->assertJsonPath('is_live', false);
    }

    public function test_unknown_code_is_not_found(): void
    {
        $event = $this->liveGalleryEvent();

        $this->getJson(route('api.v1.table.show', ['slug' => $event->slug, 'code' => 'NOPE0000']))
            ->assertNotFound();
    }

    public function test_private_event_is_forbidden(): void
    {
        $event = $this->liveGalleryEvent(['is_public' => false]);
        $table = EventTable::factory()->for($event)->create();

        $this->getJson(route('api.v1.table.show', ['slug' => $event->slug, 'code' => $table->code]))
            ->assertForbidden();
    }
}
