<?php

namespace Tests\Feature\Api\V1\Events;

use App\Enums\TicketOrderStatus;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_destroy_soft_deletes_and_keeps_the_cover_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('events/cover.webp', 'fake-bytes');

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['cover_image' => 'events/cover.webp']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson("/api/v1/host/events/{$event->id}")
            ->assertNoContent();

        $this->assertNotNull($event->fresh()->deleted_at);
        Storage::disk('public')->assertExists('events/cover.webp');
    }

    public function test_blocking_ticket_commerce_prevents_deletion(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketOrder::factory()->for($event)->create(['status' => TicketOrderStatus::Paid]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson("/api/v1/host/events/{$event->id}")
            ->assertUnprocessable();

        $this->assertNull($event->fresh()->deleted_at);
    }

    public function test_non_owner_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->deleteJson("/api/v1/host/events/{$event->id}")
            ->assertForbidden();

        $this->assertNull($event->fresh()->deleted_at);
    }
}
