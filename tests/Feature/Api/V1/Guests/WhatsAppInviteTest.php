<?php

namespace Tests\Feature\Api\V1\Guests;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Guest;
use App\Models\User;
use App\Services\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class WhatsAppInviteTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_pro_owner_gets_the_send_outcome(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['phone' => '+260971234567']);

        $this->mock(CommunicationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendWhatsAppInvitation')->once()->andReturn('sent');
        });

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}/whatsapp-invite")
            ->assertOk()
            ->assertJsonPath('outcome', 'sent');
    }

    public function test_disabled_outcome_is_passed_through(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['phone' => '+260971234567']);

        $this->mock(CommunicationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendWhatsAppInvitation')->once()->andReturn('disabled');
        });

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}/whatsapp-invite")
            ->assertOk()
            ->assertJsonPath('outcome', 'disabled');
    }

    public function test_base_tier_owner_gets_the_premium_required_403_and_nothing_is_sent(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['phone' => '+260971234567']);

        $this->mock(CommunicationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendWhatsAppInvitation');
        });

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}/whatsapp-invite")
            ->assertStatus(403)
            ->assertJsonPath('error', 'premium_required');
    }

    public function test_checkin_staff_without_manage_access_is_forbidden(): void
    {
        $owner = User::factory()->pro()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['phone' => '+260971234567']);
        EventStaff::factory()->for($event)->create(['user_id' => $staffer->id, 'accepted_at' => now()]);

        $this->mock(CommunicationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendWhatsAppInvitation');
        });

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->postJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}/whatsapp-invite")
            ->assertForbidden();
    }
}
