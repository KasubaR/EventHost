<?php

namespace Tests\Feature\Api\V1\Tickets;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutShowTest extends TestCase
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

    public function test_missing_cart_id_is_rejected(): void
    {
        $event = $this->approvedTicketedEvent();

        $this->getJson(route('api.v1.tickets.checkout.show', ['slug' => $event->slug]))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_unknown_cart_id_is_rejected_with_the_expired_hold_message(): void
    {
        $event = $this->approvedTicketedEvent();

        $this->getJson(route('api.v1.tickets.checkout.show', ['slug' => $event->slug]).'?cart_id=does-not-exist')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Your ticket hold has expired. Please choose your tickets again.');
    }

    public function test_valid_unexpired_hold_returns_the_hold_shape(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 10]);

        $hold = $this->postJson(route('api.v1.tickets.hold', ['slug' => $event->slug]), [
            'quantities' => [$type->id => 1],
        ])->json();

        $response = $this->getJson(route('api.v1.tickets.checkout.show', ['slug' => $event->slug]).'?cart_id='.$hold['cart_id']);

        $response->assertOk()
            ->assertJsonPath('cart_id', $hold['cart_id'])
            ->assertJsonPath('total', '150.00');
    }
}
