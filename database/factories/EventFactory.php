<?php

namespace Database\Factories;

use App\Enums\CommissionMode;
use App\Enums\EventProductKind;
use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 week', '+6 months');

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true).' '.fake()->randomElement(['Celebration', 'Gathering', 'Party']),
            'event_type' => fake()->randomElement(Event::EVENT_TYPES),
            'product_kind' => EventProductKind::Invitation,
            'ticketing_status' => TicketingStatus::NotApplicable,
            'commission_mode' => null,
            'description' => fake()->optional(0.7)->paragraph(),
            'event_date' => $startsAt->format('Y-m-d'),
            'event_time' => $startsAt->format('H:i:s'),
            'venue' => fake()->optional()->company(),
            'location_name' => fake()->optional()->city(),
            'latitude' => null,
            'longitude' => null,
            'cover_image' => null,
            'is_public' => true,
            'rsvp_deadline' => null,
            'guest_limit' => null,
            'allow_plus_one' => false,
            'show_guest_list' => false,
            'is_published' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
        ]);
    }

    public function ticketed(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_kind' => EventProductKind::Ticketed,
            'ticketing_status' => TicketingStatus::Draft,
            'commission_mode' => CommissionMode::Absorb,
            'is_public' => true,
            'event_type' => fake()->randomElement(Event::TICKETED_EVENT_TYPES),
        ]);
    }
}
