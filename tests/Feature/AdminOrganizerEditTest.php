<?php

namespace Tests\Feature;

use App\Enums\TicketingStatus;
use App\Models\Admin;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrganizerEditTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return $over + [
            'organizer_name' => 'Corrected Name',
            'organizer_phone' => '0977 123 456',
            'organizer_email' => 'fixed@example.com',
        ];
    }

    private function liveEvent(): Event
    {
        return Event::factory()->for(User::factory()->create())->ticketed()->create([
            'ticketing_status' => TicketingStatus::Approved,
            'organizer_name' => 'Old Name',
            'organizer_phone' => '0955 000 000',
            'organizer_email' => 'old@example.com',
            'payout_account_number' => '1234567890',
        ]);
    }

    public function test_admin_can_change_organizer_details_on_a_live_event_without_touching_payout(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $event = $this->liveEvent();
        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.ticketing.organizer', $event), $this->payload())
            ->assertRedirect(route('admin.ticketing.show', $event))
            ->assertSessionHas('status', 'ticketing-organizer-updated');

        $fresh = $event->fresh();
        $this->assertSame('Corrected Name', $fresh->organizer_name);
        $this->assertSame('fixed@example.com', $fresh->organizer_email);
        $this->assertSame('1234567890', $fresh->payout_account_number);
    }

    public function test_support_role_cannot_change_organizer_details(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $event = $this->liveEvent();
        $support = Admin::factory()->create();
        $support->assignRole('support');

        $this->actingAs($support, 'admin')
            ->patch(route('admin.ticketing.organizer', $event), $this->payload())
            ->assertForbidden();

        $this->assertSame('Old Name', $event->fresh()->organizer_name);
    }

    public function test_blank_name_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $event = $this->liveEvent();
        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.ticketing.organizer', $event), $this->payload(['organizer_name' => '']))
            ->assertSessionHasErrors('organizer_name');
    }
}
