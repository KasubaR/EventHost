<?php

namespace Tests\Feature\Api\V1\Tickets;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldTest extends TestCase
{
    use RefreshDatabase;

    private function approvedTicketedEvent(array $overrides = []): Event
    {
        return Event::factory()->ticketed()->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ], $overrides));
    }

    public function test_happy_path_returns_cart_id_items_and_expiry(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);

        $response = $this->postJson(route('api.v1.tickets.hold', ['slug' => $event->slug]), [
            'quantities' => [$type->id => 2],
        ]);

        $response->assertOk()
            ->assertJsonStructure(['cart_id', 'event', 'currency', 'hold_minutes', 'expires_at', 'items', 'total'])
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('total', '400.00');

        $this->assertNotEmpty($response->json('cart_id'));
    }

    public function test_exceeding_capacity_returns_422_with_the_purchase_exception_message(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 1]);

        $this->postJson(route('api.v1.tickets.hold', ['slug' => $event->slug]), [
            'quantities' => [$type->id => 2],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_invalid_quantities_shape_is_rejected_by_validation(): void
    {
        $event = $this->approvedTicketedEvent();

        $this->postJson(route('api.v1.tickets.hold', ['slug' => $event->slug]), [
            'quantities' => 'not-an-array',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantities');
    }

    public function test_each_hold_call_mints_its_own_fresh_cart_not_a_shared_session_cart(): void
    {
        // Deliberate behavior difference from the web flow: web's TicketCart persists one
        // cart id per event in the session, so a second hold() call re-holds into the same
        // cart. The API has no session, so every hold() call gets an independent cart_id —
        // stacking is prevented only by underlying capacity checks, not by cart reuse.
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);

        $first = $this->postJson(route('api.v1.tickets.hold', ['slug' => $event->slug]), [
            'quantities' => [$type->id => 1],
        ])->assertOk();

        $second = $this->postJson(route('api.v1.tickets.hold', ['slug' => $event->slug]), [
            'quantities' => [$type->id => 1],
        ])->assertOk();

        $this->assertNotSame($first->json('cart_id'), $second->json('cart_id'));
    }
}
