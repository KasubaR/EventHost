<?php

namespace Database\Factories;

use App\Enums\ReviewMediaType;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'source' => Review::SOURCE_USER,
            'media_type' => ReviewMediaType::Text,
            'rating' => fake()->numberBetween(3, 5),
            'body' => fake()->paragraph(),
            'author_name' => fake()->name(),
            'author_context' => 'Wedding · Lusaka',
            'author_photo' => null,
            'status' => ReviewStatus::Pending,
            'is_featured' => false,
            'featured_sort_order' => 0,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReviewStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function rejected(string $note = 'Too short to publish.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReviewStatus::Rejected,
            'moderation_note' => $note,
        ]);
    }

    /**
     * Approved *and* featured — the only combination the homepage renders.
     */
    public function featured(int $sortOrder = 0): static
    {
        return $this->approved()->state(fn (array $attributes) => [
            'is_featured' => true,
            'featured_sort_order' => $sortOrder,
        ]);
    }

    /**
     * Admin-authored video review (phase 2). No user or event behind it.
     */
    public function video(?string $videoRef = 'youtube:dQw4w9WgXcQ'): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'event_id' => null,
            'source' => Review::SOURCE_ADMIN,
            'media_type' => ReviewMediaType::Video,
            'video_ref' => $videoRef,
        ]);
    }
}
