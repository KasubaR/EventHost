<?php

namespace Database\Factories;

use App\Enums\RsvpStatus;
use App\Models\Guest;
use App\Models\Rsvp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rsvp>
 */
class RsvpFactory extends Factory
{
    protected $model = Rsvp::class;

    public function configure(): static
    {
        return $this->afterMaking(function (Rsvp $rsvp): void {
            if ($rsvp->guest_id === null) {
                return;
            }

            $eventId = Guest::query()->whereKey($rsvp->guest_id)->value('event_id');
            if ($eventId !== null) {
                $rsvp->event_id = (int) $eventId;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guest_id' => Guest::factory(),
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 1,
            'message' => fake()->optional()->sentence(),
            'meal_preference' => null,
            'transportation_note' => null,
            'song_request' => null,
        ];
    }

    public function accepted(int $count = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RsvpStatus::Accepted,
            'attendee_count' => $count,
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RsvpStatus::Declined,
            'attendee_count' => 0,
        ]);
    }

    public function maybe(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RsvpStatus::Maybe,
            'attendee_count' => 0,
        ]);
    }

    /**
     * Align guest_id's event with event_id.
     */
    public function forGuest(Guest $guest): static
    {
        return $this->state(fn (array $attributes) => [
            'event_id' => $guest->event_id,
            'guest_id' => $guest->id,
        ]);
    }
}
