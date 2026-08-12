<?php

namespace Database\Factories;

use App\Enums\PhotoStatus;
use App\Models\Event;
use App\Models\EventPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventPhoto>
 */
class EventPhotoFactory extends Factory
{
    protected $model = EventPhoto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = 'event-photos/'.fake()->uuid().'.webp';
        $thumbnail = 'event-photos/thumbs/'.fake()->uuid().'.webp';

        return [
            'event_id' => Event::factory(),
            'event_table_id' => null,
            'path' => $path,
            'thumbnail_path' => $thumbnail,
            'uploader_name' => fake()->optional()->firstName(),
            'status' => PhotoStatus::Approved,
            'ip_hash' => hash('sha256', fake()->ipv4()),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PhotoStatus::Pending,
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PhotoStatus::Hidden,
        ]);
    }
}
