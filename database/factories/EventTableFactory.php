<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventTable>
 */
class EventTableFactory extends Factory
{
    protected $model = EventTable::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'label' => 'Table '.fake()->unique()->numberBetween(1, 200),
            'code' => EventTable::generateUniqueCode(),
            'sort_order' => 0,
        ];
    }
}
